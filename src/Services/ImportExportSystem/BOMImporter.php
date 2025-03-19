<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
namespace App\Services\ImportExportSystem;

use App\Entity\AssemblySystem\Assembly;
use App\Entity\AssemblySystem\AssemblyBOMEntry;
use App\Entity\Parts\Category;
use App\Entity\Parts\Manufacturer;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Repository\DBElementRepository;
use App\Repository\PartRepository;
use App\Repository\Parts\CategoryRepository;
use App\Repository\Parts\ManufacturerRepository;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use League\Csv\Reader;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\OptionsResolver\OptionsResolver;
use RuntimeException;
use UnexpectedValueException;

/**
 * @see \App\Tests\Services\ImportExportSystem\BOMImporterTest
 */
class BOMImporter
{
    private const MAP_KICAD_PCB_FIELDS = [
        0 => 'Id',
        1 => 'Designator',
        2 => 'Package',
        3 => 'Quantity',
        4 => 'Designation',
        5 => 'Supplier and ref',
    ];

    private PartRepository $partRepository;

    private ManufacturerRepository $manufacturerRepository;

    private CategoryRepository $categoryRepository;

    private DBElementRepository $assemblyBOMEntryRepository;

    public function __construct(EntityManagerInterface $entityManager) {
        $this->partRepository = $entityManager->getRepository(Part::class);
        $this->manufacturerRepository = $entityManager->getRepository(Manufacturer::class);
        $this->categoryRepository = $entityManager->getRepository(Category::class);
        $this->assemblyBOMEntryRepository = $entityManager->getRepository(AssemblyBOMEntry::class);
    }

    protected function configureOptions(OptionsResolver $resolver): OptionsResolver
    {
        $resolver->setRequired('type');
        $resolver->setAllowedValues('type', ['kicad_pcbnew', 'json']);

        return $resolver;
    }

    /**
     * Converts the given file into an array of BOM entries using the given options and save them into the given project.
     * The changes are not saved into the database yet.
     * @return ProjectBOMEntry[]
     */
    public function importFileIntoProject(File $file, Project $project, array $options): array
    {
        $bom_entries = $this->fileToBOMEntries($file, $options);

        //Assign the bom_entries to the project
        foreach ($bom_entries as $bom_entry) {
            $project->addBomEntry($bom_entry);
        }

        return $bom_entries;
    }

    /**
     * Converts the given file into an array of BOM entries using the given options and save them into the given assembly.
     * The changes are not saved into the database yet.
     * @return AssemblyBOMEntry[]
     */
    public function importFileIntoAssembly(File $file, Assembly $assembly, array $options): array
    {
        $bomEntries = $this->fileToBOMEntries($file, $options, AssemblyBOMEntry::class);

        //Assign the bom_entries to the assembly
        foreach ($bomEntries as $bom_entry) {
            $assembly->addBomEntry($bom_entry);
        }

        return $bomEntries;
    }

    /**
     * Converts the given file into an array of BOM entries using the given options.
     * @return ProjectBOMEntry[]|AssemblyBOMEntry[]
     */
    public function fileToBOMEntries(File $file, array $options, string $objectType = ProjectBOMEntry::class): array
    {
        return $this->stringToBOMEntries($file->getContent(), $options, $objectType);
    }

    /**
     * Import string data into an array of BOM entries, which are not yet assigned to a project.
     * @param  string  $data The data to import
     * @param  array  $options An array of options
     * @return ProjectBOMEntry[]|AssemblyBOMEntry[] An array of imported entries
     */
    public function stringToBOMEntries(string $data, array $options, string $objectType = ProjectBOMEntry::class): array
    {
        $resolver = new OptionsResolver();
        $resolver = $this->configureOptions($resolver);
        $options = $resolver->resolve($options);

        return match ($options['type']) {
            'kicad_pcbnew' => $this->parseKiCADPCB($data, $options, $objectType),
            'json' => $this->parseJson($data, $options, $objectType),
            default => throw new InvalidArgumentException('Invalid import type!'),
        };
    }

    private function parseKiCADPCB(string $data, array $options = [], string $objectType = ProjectBOMEntry::class): array
    {
        $csv = Reader::createFromString($data);
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

        $bom_entries = [];

        foreach ($csv->getRecords() as $offset => $entry) {
            //Translate the german field names to english
            $entry = $this->normalizeColumnNames($entry);

            //Ensure that the entry has all required fields
            if (!isset ($entry['Designator'])) {
                throw new UnexpectedValueException('Designator missing at line '.($offset + 1).'!');
            }
            if (!isset ($entry['Package'])) {
                throw new UnexpectedValueException('Package missing at line '.($offset + 1).'!');
            }
            if (!isset ($entry['Designation'])) {
                throw new UnexpectedValueException('Designation missing at line '.($offset + 1).'!');
            }
            if (!isset ($entry['Quantity'])) {
                throw new UnexpectedValueException('Quantity missing at line '.($offset + 1).'!');
            }

            $bom_entry = $objectType === ProjectBOMEntry::class ? new ProjectBOMEntry() : new AssemblyBOMEntry();
            if ($objectType === ProjectBOMEntry::class) {
                $bom_entry->setName($entry['Designation'] . ' (' . $entry['Package'] . ')');
            } else {
                $bom_entry->setName($entry['Designation']);
            }

            $bom_entry->setMountnames($entry['Designator'] ?? '');
            $bom_entry->setComment($entry['Supplier and ref'] ?? '');
            $bom_entry->setQuantity((float) ($entry['Quantity'] ?? 1));

            $bom_entries[] = $bom_entry;
        }

        return $bom_entries;
    }

    private function parseJson(string $data, array $options = [], string $objectType = ProjectBOMEntry::class): array
    {
        $result = [];

        $data = json_decode($data, true);

        foreach ($data as $entry) {
            // Check quantity
            if (!isset($entry['quantity'])) {
                throw new UnexpectedValueException('quantity missing');
            }
            if (!is_float($entry['quantity']) || $entry['quantity'] <= 0) {
                throw new UnexpectedValueException('quantity expected as float greater than 0.0');
            }

            // Check name
            if (isset($entry['name']) && !is_string($entry['name'])) {
                throw new UnexpectedValueException('name of part list entry expected as string');
            }

            // Check if part is assigned with relevant information
            if (isset($entry['part'])) {
                if (!is_array($entry['part'])) {
                    throw new UnexpectedValueException('The property "part" should be an array');
                }

                $partIdValid = isset($entry['part']['id']) && is_int($entry['part']['id']) && $entry['part']['id'] > 0;
                $partNameValid = isset($entry['part']['name']) && is_string($entry['part']['name']) && trim($entry['part']['name']) !== '';
                $partMpnrValid = isset($entry['part']['mpnr']) && is_string($entry['part']['mpnr']) && trim($entry['part']['mpnr']) !== '';
                $partIpnValid = isset($entry['part']['ipn']) && is_string($entry['part']['ipn']) && trim($entry['part']['ipn']) !== '';

                if (!$partIdValid && !$partNameValid && !$partMpnrValid && !$partIpnValid) {
                    throw new UnexpectedValueException(
                        'The property "part" must have either assigned: "id" as integer greater than 0, "name", "mpnr", or "ipn" as non-empty string'
                    );
                }

                $part = $partIdValid ? $this->partRepository->findOneBy(['id' => $entry['part']['id']]) : null;
                $part = $part ?? ($partMpnrValid ? $this->partRepository->findOneBy(['manufacturer_product_number' => trim($entry['part']['mpnr'])]) : null);
                $part = $part ?? ($partIpnValid ? $this->partRepository->findOneBy(['ipn' => trim($entry['part']['ipn'])]) : null);
                $part = $part ?? ($partNameValid ? $this->partRepository->findOneBy(['name' => trim($entry['part']['name'])]) : null);

                if ($part === null) {
                    $part = new Part();
                    $part->setName($entry['part']['name']);
                }

                if ($partNameValid && $part->getName() !== trim($entry['part']['name'])) {
                    throw new RuntimeException(sprintf('Part name does not match exact the given name. Given for import: %s, found part: %s', $entry['part']['name'], $part->getName()));
                }

                if ($partIpnValid && $part->getManufacturerProductNumber() !== trim($entry['part']['mpnr'])) {
                    throw new RuntimeException(sprintf('Part mpnr does not match exact the given mpnr. Given for import: %s, found part: %s', $entry['part']['mpnr'], $part->getManufacturerProductNumber()));
                }

                if ($partIpnValid && $part->getIpn() !== trim($entry['part']['ipn'])) {
                    throw new RuntimeException(sprintf('Part ipn does not match exact the given ipn. Given for import: %s, found part: %s', $entry['part']['ipn'], $part->getIpn()));
                }

                // Part: Description check
                if (isset($entry['part']['description']) && !is_null($entry['part']['description'])) {
                    if (!is_string($entry['part']['description']) || trim($entry['part']['description']) === '') {
                        throw new UnexpectedValueException('The property path "part.description" must be a non-empty string if not null');
                    }
                }
                $partDescription = $entry['part']['description'] ?? '';

                // Part: Manufacturer check
                $manufacturerIdValid = false;
                $manufacturerNameValid = false;
                if (array_key_exists('manufacturer', $entry['part'])) {
                    if (!is_array($entry['part']['manufacturer'])) {
                        throw new UnexpectedValueException('The property path "part.manufacturer" must be an array');
                    }

                    $manufacturerIdValid = isset($entry['part']['manufacturer']['id']) && is_int($entry['part']['manufacturer']['id']) && $entry['part']['manufacturer']['id'] > 0;
                    $manufacturerNameValid = isset($entry['part']['manufacturer']['name']) && is_string($entry['part']['manufacturer']['name']) && trim($entry['part']['manufacturer']['name']) !== '';

                    // Stellen sicher, dass mindestens eine Bedingung für manufacturer erfüllt sein muss
                    if (!$manufacturerIdValid && !$manufacturerNameValid) {
                        throw new UnexpectedValueException(
                            'The property "manufacturer" must have either assigned: "id" as integer greater than 0, or "name" as non-empty string'
                        );
                    }
                }

                $manufacturer = $manufacturerIdValid ? $this->manufacturerRepository->findOneBy(['id' => $entry['part']['manufacturer']['id']]) : null;
                $manufacturer = $manufacturer ?? ($manufacturerNameValid ? $this->manufacturerRepository->findOneBy(['name' => trim($entry['part']['manufacturer']['name'])]) : null);

                if ($manufacturer === null) {
                    throw new RuntimeException(
                        'Manufacturer not found'
                    );
                }

                if ($manufacturerNameValid && $manufacturer->getName() !== trim($entry['part']['manufacturer']['name'])) {
                    throw new RuntimeException(sprintf('Manufacturer name does not match exact the given name. Given for import: %s, found manufacturer: %s',  $entry['manufacturer']['name'], $manufacturer->getName()));
                }

                // Part: Category check
                $categoryIdValid = false;
                $categoryNameValid = false;
                if (array_key_exists('category', $entry['part'])) {
                    if (!is_array($entry['part']['category'])) {
                        throw new UnexpectedValueException('part.category must be an array');
                    }

                    $categoryIdValid = isset($entry['part']['category']['id']) && is_int($entry['part']['category']['id']) && $entry['part']['category']['id'] > 0;
                    $categoryNameValid = isset($entry['part']['category']['name']) && is_string($entry['part']['category']['name']) && trim($entry['part']['category']['name']) !== '';

                    if (!$categoryIdValid && !$categoryNameValid) {
                        throw new UnexpectedValueException(
                            'The property "category" must have either assigned: "id" as integer greater than 0, or "name" as non-empty string'
                        );
                    }
                }

                $category = $categoryIdValid ? $this->categoryRepository->findOneBy(['id' => $entry['part']['category']['id']]) : null;
                $category = $category ?? ($categoryNameValid ? $this->categoryRepository->findOneBy(['name' => trim($entry['part']['category']['name'])]) : null);

                if ($category === null) {
                    throw new RuntimeException(
                        'Category not found'
                    );
                }

                if ($categoryNameValid && $category->getName() !== trim($entry['part']['category']['name'])) {
                    throw new RuntimeException(sprintf('Category name does not match exact the given name. Given for import: %s, found category: %s',  $entry['category']['name'], $category->getName()));
                }

                $part->setDescription($partDescription);
                $part->setManufacturer($manufacturer);
                $part->setCategory($category);

                if ($partMpnrValid) {
                    $part->setManufacturerProductNumber($entry['part']['mpnr'] ?? '');
                }
                if ($partIpnValid) {
                    $part->setIpn($entry['part']['ipn'] ?? '');
                }

                if ($objectType === AssemblyBOMEntry::class) {
                    $bomEntry = $this->assemblyBOMEntryRepository->findOneBy(['part' => $part]);

                    if ($bomEntry === null) {
                        $name = isset($entry['name']) && $entry['name'] !== null ? trim($entry['name']) : '';
                        $bomEntry = $this->assemblyBOMEntryRepository->findOneBy(['name' => $name]);

                        if ($bomEntry === null) {
                            $bomEntry = new AssemblyBOMEntry();
                        }
                    }
                } else {
                    $bomEntry = new ProjectBOMEntry();
                }

                $bomEntry->setQuantity($entry['quantity']);
                $bomEntry->setName($entry['name'] ?? '');

                $bomEntry->setPart($part);
            }

            $result[] = $bomEntry;
        }

        return $result;
    }

    /**
     * This function uses the order of the fields in the CSV files to make them locale independent.
     * @param  array  $entry
     * @return array
     */
    private function normalizeColumnNames(array $entry): array
    {
        $out = [];

        //Map the entry order to the correct column names
        foreach (array_values($entry) as $index => $field) {
            if ($index > 5) {
                break;
            }

            //@phpstan-ignore-next-line We want to keep this check just to be safe when something changes
            $new_index = self::MAP_KICAD_PCB_FIELDS[$index] ?? throw new UnexpectedValueException('Invalid field index!');
            $out[$new_index] = $field;
        }

        return $out;
    }
}
