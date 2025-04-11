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
use Symfony\Contracts\Translation\TranslatorInterface;
use UnexpectedValueException;
use Symfony\Component\Validator\ConstraintViolation;

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

    private string $jsonRoot = '';

    private PartRepository $partRepository;

    private ManufacturerRepository $manufacturerRepository;

    private CategoryRepository $categoryRepository;

    private DBElementRepository $assemblyBOMEntryRepository;
    private TranslatorInterface $translator;

    public function __construct(EntityManagerInterface $entityManager, TranslatorInterface $translator) {
        $this->partRepository = $entityManager->getRepository(Part::class);
        $this->manufacturerRepository = $entityManager->getRepository(Manufacturer::class);
        $this->categoryRepository = $entityManager->getRepository(Category::class);
        $this->assemblyBOMEntryRepository = $entityManager->getRepository(AssemblyBOMEntry::class);
        $this->translator = $translator;
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
     * Converts the given file into an ImporterResult with an array of BOM entries using the given options and save them into the given assembly.
     * The changes are not saved into the database yet.
     */
    public function importFileIntoAssembly(File $file, Assembly $assembly, array $options): ImporterResult
    {
        $importerResult = $this->fileToImporterResult($file, $options, AssemblyBOMEntry::class);

        if ($importerResult->getViolations()->count() === 0) {
            //Assign the bom_entries to the assembly
            foreach ($importerResult->getBomEntries() as $bomEntry) {
                $assembly->addBomEntry($bomEntry);
            }
        }

        return $importerResult;
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
     * Converts the given file into an ImporterResult with an array of BOM entries using the given options.
     */
    public function fileToImporterResult(File $file, array $options, string $objectType = ProjectBOMEntry::class): ImporterResult
    {
        return $this->stringToImporterResult($file->getContent(), $options, $objectType);
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
            'kicad_pcbnew' => $this->parseKiCADPCB($data, $options, $objectType)->getBomEntries(),
            default => throw new InvalidArgumentException('Invalid import type!'),
        };
    }

    /**
     * Import string data into an array of BOM entries, which are not yet assigned to a project.
     * @param  string  $data The data to import
     * @param  array  $options An array of options
     * @return ProjectBOMEntry[]|AssemblyBOMEntry[] An array of imported entries
     */
    public function stringToImporterResult(string $data, array $options, string $objectType = ProjectBOMEntry::class): ImporterResult
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

    private function parseKiCADPCB(string $data, array $options = [], string $objectType = ProjectBOMEntry::class): ImporterResult
    {
        $result = new ImporterResult();

        $csv = Reader::createFromString($data);
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

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

            $result->addBomEntry($bom_entry);
        }

        return $result;
    }

    private function parseJson(string $data, array $options = [], string $objectType = ProjectBOMEntry::class): ImporterResult
    {
        $result = new ImporterResult();
        $this->jsonRoot = 'JSON Import for '.$objectType === ProjectBOMEntry::class ? 'Project' : 'Assembly';

        $data = json_decode($data, true);

        foreach ($data as $key => $entry) {
            // Check quantity
            if (!isset($entry['quantity'])) {
                $result->addViolation($this->buildJsonViolation(
                    'validator.bom_importer.json.quantity.required',
                    "entry[$key].quantity"
                ));
            }

            if (isset($entry['quantity']) && (!is_float($entry['quantity']) || $entry['quantity'] <= 0)) {
                $result->addViolation($this->buildJsonViolation(
                    'validator.bom_importer.json.quantity.float',
                    "entry[$key].quantity",
                    $entry['quantity']
                ));
            }

            // Check name
            if (isset($entry['name']) && !is_string($entry['name'])) {
                $result->addViolation($this->buildJsonViolation(
                    'validator.bom_importer.json.parameter.string.notEmpty',
                    "entry[$key].name",
                    $entry['name']
                ));
            }

            // Check if part is assigned with relevant information
            if (isset($entry['part'])) {
                if (!is_array($entry['part'])) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.array',
                        "entry[$key].part",
                        $entry['part']
                    ));
                }

                $partIdValid = isset($entry['part']['id']) && is_int($entry['part']['id']) && $entry['part']['id'] > 0;
                $partNameValid = isset($entry['part']['name']) && is_string($entry['part']['name']) && trim($entry['part']['name']) !== '';
                $partMpnrValid = isset($entry['part']['mpnr']) && is_string($entry['part']['mpnr']) && trim($entry['part']['mpnr']) !== '';
                $partIpnValid = isset($entry['part']['ipn']) && is_string($entry['part']['ipn']) && trim($entry['part']['ipn']) !== '';

                if (!$partIdValid && !$partNameValid && !$partMpnrValid && !$partIpnValid) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.subproperties',
                        "entry[$key].part",
                        $entry['part'],
                        ['%propertyString%' => '"id", "name", "mpnr", or "ipn"']
                    ));
                }

                $part = $partIdValid ? $this->partRepository->findOneBy(['id' => $entry['part']['id']]) : null;
                $part = $part ?? ($partMpnrValid ? $this->partRepository->findOneBy(['manufacturer_product_number' => trim($entry['part']['mpnr'])]) : null);
                $part = $part ?? ($partIpnValid ? $this->partRepository->findOneBy(['ipn' => trim($entry['part']['ipn'])]) : null);
                $part = $part ?? ($partNameValid ? $this->partRepository->findOneBy(['name' => trim($entry['part']['name'])]) : null);

                if ($part === null) {
                    $value = sprintf('part.id: %s, part.mpnr: %s, part.ipn: %s, part.name: %s',
                        isset($entry['part']['id']) ? '<strong>' . $entry['part']['id'] . '</strong>' : '-',
                        isset($entry['part']['mpnr']) ? '<strong>' . $entry['part']['mpnr'] . '</strong>' : '-',
                        isset($entry['part']['ipn']) ? '<strong>' . $entry['part']['ipn'] . '</strong>' : '-',
                        isset($entry['part']['name']) ? '<strong>' . $entry['part']['name'] . '</strong>' : '-',
                    );

                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.notFoundFor',
                        "entry[$key].part",
                        $entry['part'],
                        ['%value%' => $value]
                    ));
                }

                if ($partNameValid && $part !== null && isset($entry['part']['name']) && $part->getName() !== trim($entry['part']['name'])) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.noExactMatch',
                        "entry[$key].part.name",
                        $entry['part']['name'],
                        [
                            '%importValue%' => '<strong>' . $entry['part']['name'] . '</strong>',
                            '%foundId%' => $part->getID(),
                            '%foundValue%' => '<strong>' . $part->getName() . '</strong>'
                        ]
                    ));
                }

                if ($partMpnrValid && $part !== null && isset($entry['part']['mpnr']) && $part->getManufacturerProductNumber() !== trim($entry['part']['mpnr'])) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.noExactMatch',
                        "entry[$key].part.mpnr",
                        $entry['part']['mpnr'],
                        [
                            '%importValue%' => '<strong>' . $entry['part']['mpnr'] . '</strong>',
                            '%foundId%' => $part->getID(),
                            '%foundValue%' => '<strong>' . $part->getManufacturerProductNumber() . '</strong>'
                        ]
                    ));
                }

                if ($partIpnValid && $part !== null && isset($entry['part']['ipn']) && $part->getIpn() !== trim($entry['part']['ipn'])) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.noExactMatch',
                        "entry[$key].part.ipn",
                        $entry['part']['ipn'],
                        [
                            '%importValue%' => '<strong>' . $entry['part']['ipn'] . '</strong>',
                            '%foundId%' => $part->getID(),
                            '%foundValue%' => '<strong>' . $part->getIpn() . '</strong>'
                        ]
                    ));
                }

                // Part: Description check
                if (isset($entry['part']['description'])) {
                    if (!is_string($entry['part']['description']) || trim($entry['part']['description']) === '') {
                        $result->addViolation($this->buildJsonViolation(
                            'validator.bom_importer.json.parameter.string.notEmpty',
                            'entry[$key].part.description',
                            $entry['part']['description']
                        ));
                    }
                }

                $partDescription = $entry['part']['description'] ?? '';

                // Part: Manufacturer check
                $manufacturerIdValid = false;
                $manufacturerNameValid = false;
                if (array_key_exists('manufacturer', $entry['part'])) {
                    if (!is_array($entry['part']['manufacturer'])) {
                        $result->addViolation($this->buildJsonViolation(
                            'validator.bom_importer.json.parameter.array',
                            'entry[$key].part.manufacturer',
                            $entry['part']['manufacturer']) ?? null
                        );
                    }

                    $manufacturerIdValid = isset($entry['part']['manufacturer']['id']) && is_int($entry['part']['manufacturer']['id']) && $entry['part']['manufacturer']['id'] > 0;
                    $manufacturerNameValid = isset($entry['part']['manufacturer']['name']) && is_string($entry['part']['manufacturer']['name']) && trim($entry['part']['manufacturer']['name']) !== '';

                    // Stellen sicher, dass mindestens eine Bedingung für manufacturer erfüllt sein muss
                    if (!$manufacturerIdValid && !$manufacturerNameValid) {
                        $result->addViolation($this->buildJsonViolation(
                            'validator.bom_importer.json.parameter.manufacturerOrCategoryWithSubProperties',
                            "entry[$key].part.manufacturer",
                            $entry['part']['manufacturer'],
                        ));
                    }
                }

                $manufacturer = $manufacturerIdValid ? $this->manufacturerRepository->findOneBy(['id' => $entry['part']['manufacturer']['id']]) : null;
                $manufacturer = $manufacturer ?? ($manufacturerNameValid ? $this->manufacturerRepository->findOneBy(['name' => trim($entry['part']['manufacturer']['name'])]) : null);

                if (($manufacturerIdValid || $manufacturerNameValid) && $manufacturer === null) {
                    $value = sprintf(
                        'manufacturer.id: %s, manufacturer.name: %s',
                        isset($entry['part']['manufacturer']['id']) && $entry['part']['manufacturer']['id'] !== null ? '<strong>' . $entry['part']['manufacturer']['id'] . '</strong>' : '-',
                        isset($entry['part']['manufacturer']['name']) && $entry['part']['manufacturer']['name'] !== null ? '<strong>' . $entry['part']['manufacturer']['name'] . '</strong>' : '-'
                    );

                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.notFoundFor',
                        "entry[$key].part.manufacturer",
                        $entry['part']['manufacturer'],
                        ['%value%' => $value]
                    ));
                }

                if ($manufacturerNameValid && $manufacturer !== null && isset($entry['part']['manufacturer']['name']) && $manufacturer->getName() !== trim($entry['part']['manufacturer']['name'])) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.noExactMatch',
                        "entry[$key].part.manufacturer.name",
                        $entry['part']['manufacturer']['name'],
                        [
                            '%importValue%' => '<strong>' . $entry['part']['manufacturer']['name'] . '</strong>',
                            '%foundId%' => $manufacturer->getID(),
                            '%foundValue%' => '<strong>' . $manufacturer->getName() . '</strong>'
                        ]
                    ));
                }

                // Part: Category check
                $categoryIdValid = false;
                $categoryNameValid = false;
                if (array_key_exists('category', $entry['part'])) {
                    if (!is_array($entry['part']['category'])) {
                        $result->addViolation($this->buildJsonViolation(
                            'validator.bom_importer.json.parameter.array',
                            'entry[$key].part.category',
                            $entry['part']['category']) ?? null
                        );
                    }

                    $categoryIdValid = isset($entry['part']['category']['id']) && is_int($entry['part']['category']['id']) && $entry['part']['category']['id'] > 0;
                    $categoryNameValid = isset($entry['part']['category']['name']) && is_string($entry['part']['category']['name']) && trim($entry['part']['category']['name']) !== '';

                    if (!$categoryIdValid && !$categoryNameValid) {
                        $result->addViolation($this->buildJsonViolation(
                            'validator.bom_importer.json.parameter.manufacturerOrCategoryWithSubProperties',
                            "entry[$key].part.category",
                            $entry['part']['category']
                        ));
                    }
                }

                $category = $categoryIdValid ? $this->categoryRepository->findOneBy(['id' => $entry['part']['category']['id']]) : null;
                $category = $category ?? ($categoryNameValid ? $this->categoryRepository->findOneBy(['name' => trim($entry['part']['category']['name'])]) : null);

                if (($categoryIdValid || $categoryNameValid) && $category === null) {
                    $value = sprintf(
                        'category.id: %s, category.name: %s',
                        isset($entry['part']['category']['id']) && $entry['part']['category']['id'] !== null ? '<strong>' . $entry['part']['category']['id'] . '</strong>' : '-',
                        isset($entry['part']['category']['name']) && $entry['part']['category']['name'] !== null ? '<strong>' . $entry['part']['category']['name'] . '</strong>' : '-'
                    );

                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.notFoundFor',
                        "entry[$key].part.category",
                        $entry['part']['category'],
                        ['%value%' => $value]
                    ));
                }

                if ($categoryNameValid && $category !== null && isset($entry['part']['category']['name']) && $category->getName() !== trim($entry['part']['category']['name'])) {
                    $result->addViolation($this->buildJsonViolation(
                        'validator.bom_importer.json.parameter.noExactMatch',
                        "entry[$key].part.category.name",
                        $entry['part']['category']['name'],
                        [
                            '%importValue%' => '<strong>' . $entry['part']['category']['name'] . '</strong>',
                            '%foundId%' => $category->getID(),
                            '%foundValue%' => '<strong>' . $category->getName() . '</strong>'
                        ]
                    ));
                }

                if ($result->getViolations()->count() > 0) {
                    continue;
                }

                if ($partDescription !== '') {
                    //Beim Import / Aktualisieren von zugehörigen Bauteilen zu einer Baugruppe die Beschreibung des Bauteils mit übernehmen.
                    $part->setDescription($partDescription);
                }

                if ($manufacturer !== null && $manufacturer->getID() !== $part->getManufacturerID()) {
                    //Beim Import / Aktualisieren von zugehörigen Bauteilen zu einer Baugruppe des Hersteller des Bauteils mit übernehmen.
                    $part->setManufacturer($manufacturer);
                }

                if ($category !== null && $category->getID() !== $part->getCategoryID()) {
                    //Beim Import / Aktualisieren von zugehörigen Bauteilen zu einer Baugruppe die Kategorie des Bauteils mit übernehmen.
                    $part->setCategory($category);
                }

                if ($objectType === AssemblyBOMEntry::class) {
                    $bomEntry = $this->assemblyBOMEntryRepository->findOneBy(['part' => $part]);

                    if ($bomEntry === null) {
                        if (isset($entry['name']) && $entry['name'] !== '') {
                            $bomEntry = $this->assemblyBOMEntryRepository->findOneBy(['name' => $entry['name']]);
                        }

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

                $result->addBomEntry($bomEntry);
            } else {
                //Eintrag ohne Part-Relation in die Bauteilliste aufnehmen

                if ($objectType === AssemblyBOMEntry::class) {
                    $bomEntry = new AssemblyBOMEntry();
                } else {
                    $bomEntry = new ProjectBOMEntry();
                }

                $bomEntry->setQuantity($entry['quantity']);
                $bomEntry->setName($entry['name'] ?? '');

                $result->addBomEntry($bomEntry);
            }
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

    private function buildJsonViolation(string $message, string $propertyPath, mixed $invalidValue = null, array $parameters = []): ConstraintViolation
    {
        return new ConstraintViolation(
            message: $this->translator->trans($message, $parameters, 'validators'),
            messageTemplate: $message,
            parameters: $parameters,
            root: $this->jsonRoot,
            propertyPath: $propertyPath,
            invalidValue: $invalidValue
        );
    }
}
