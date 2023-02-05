<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Base\AbstractDBElement;
use App\Entity\LogSystem\AbstractLogEntry;
use App\Entity\LogSystem\CollectionElementDeleted;
use App\Entity\LogSystem\ElementCreatedLogEntry;
use App\Entity\LogSystem\ElementDeletedLogEntry;
use App\Entity\LogSystem\ElementEditedLogEntry;
use App\Entity\UserSystem\User;
use DateTime;
use RuntimeException;

class LogEntryRepository extends DBElementRepository
{
    public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
    {
        //Emulate a target element criteria by splitting it manually in the needed criterias
        if (isset($criteria['target']) && $criteria['target'] instanceof AbstractDBElement) {
            /** @var AbstractDBElement $element */
            $element = $criteria['target'];
            $criteria['target_id'] = $element->getID();
            $criteria['target_type'] = AbstractLogEntry::targetTypeClassToID(get_class($element));
            unset($criteria['target']);
        }

        return parent::findBy($criteria, $orderBy, $limit, $offset); // TODO: Change the autogenerated stub
    }

    /**
     * Find log entries associated with the given element (the history of the element).
     *
     * @param AbstractDBElement $element The element for which the history should be generated
     * @param  string  $order   By default, the newest entries are shown first. Change this to ASC to show the oldest entries first.
     * @param null              $limit
     * @param null              $offset
     *
     * @return AbstractLogEntry[]
     */
    public function getElementHistory(AbstractDBElement $element, string $order = 'DESC', $limit = null, $offset = null): array
    {
        return $this->findBy(['element' => $element], ['timestamp' => $order], $limit, $offset);
    }

    /**
     * Try to get a log entry that contains the information to undete a given element.
     *
     * @param string $class The class of the element that should be undeleted
     * @param int    $id    The ID of the element that should be deleted
     */
    public function getUndeleteDataForElement(string $class, int $id): ElementDeletedLogEntry
    {
        $qb = $this->createQueryBuilder('log');
        $qb->select('log')
            //->where('log INSTANCE OF App\Entity\LogSystem\ElementEditedLogEntry')
            ->where('log INSTANCE OF '.ElementDeletedLogEntry::class)
            ->andWhere('log.target_type = :target_type')
            ->andWhere('log.target_id = :target_id')
            ->orderBy('log.timestamp', 'DESC')
            ->setMaxResults(1);

        $qb->setParameters([
            'target_type' => AbstractLogEntry::targetTypeClassToID($class),
            'target_id' => $id,
        ]);

        $query = $qb->getQuery();

        $results = $query->execute();

        if (empty($results)) {
            throw new RuntimeException('No undelete data could be found for this element');
        }

        return $results[0];
    }

    /**
     * Gets all log entries that are related to time travelling.
     *
     * @param AbstractDBElement $element The element for which the time travel data should be retrieved
     * @param DateTime         $until   Back to which timestamp should the data be get (including the timestamp)
     *
     * @return AbstractLogEntry[]
     */
    public function getTimetravelDataForElement(AbstractDBElement $element, DateTime $until): array
    {
        $qb = $this->createQueryBuilder('log');
        $qb->select('log')
            //->where('log INSTANCE OF App\Entity\LogSystem\ElementEditedLogEntry')
            ->where('log INSTANCE OF '.ElementEditedLogEntry::class)
            ->orWhere('log INSTANCE OF '.CollectionElementDeleted::class)
            ->andWhere('log.target_type = :target_type')
            ->andWhere('log.target_id = :target_id')
            ->andWhere('log.timestamp >= :until')
            ->orderBy('log.timestamp', 'DESC');

        $qb->setParameters([
            'target_type' => AbstractLogEntry::targetTypeClassToID(get_class($element)),
            'target_id' => $element->getID(),
            'until' => $until,
        ]);

        $query = $qb->getQuery();

        return $query->execute();
    }

    /**
     * Check if the given element has existed at the given timestamp.
     *
     * @return bool True if the element existed at the given timestamp
     */
    public function getElementExistedAtTimestamp(AbstractDBElement $element, DateTime $timestamp): bool
    {
        $qb = $this->createQueryBuilder('log');
        $qb->select('count(log)')
            ->where('log INSTANCE OF '.ElementCreatedLogEntry::class)
            ->andWhere('log.target_type = :target_type')
            ->andWhere('log.target_id = :target_id')
            ->andWhere('log.timestamp >= :until')
            ->orderBy('log.timestamp', 'DESC');

        $qb->setParameters([
            'target_type' => AbstractLogEntry::targetTypeClassToID(get_class($element)),
            'target_id' => $element->getID(),
            'until' => $timestamp,
        ]);

        $query = $qb->getQuery();
        $count = $query->getSingleScalarResult();

        return !($count > 0);
    }

    /**
     * Gets the last log entries ordered by timestamp.
     *
     * @param  string  $order
     * @param null   $limit
     * @param null   $offset
     */
    public function getLogsOrderedByTimestamp(string $order = 'DESC', $limit = null, $offset = null): array
    {
        return $this->findBy([], ['timestamp' => $order], $limit, $offset);
    }

    /**
     * Gets the target element associated with the logentry.
     *
     * @return AbstractDBElement|null returns the associated DBElement or null if the log either has no target or the element
     *                                was deleted from DB
     *
     */
    public function getTargetElement(AbstractLogEntry $logEntry): ?AbstractDBElement
    {
        $class = $logEntry->getTargetClass();
        $id = $logEntry->getTargetID();

        if (null === $class || null === $id) {
            return null;
        }

        return $this->getEntityManager()->find($class, $id);
    }

    /**
     * Returns the last user that has edited the given element.
     *
     * @return User|null a user object, or null if no user could be determined
     */
    public function getLastEditingUser(AbstractDBElement $element): ?User
    {
        return $this->getLastUser($element, ElementEditedLogEntry::class);
    }

    /**
     * Returns the user that has created the given element.
     *
     * @return User|null a user object, or null if no user could be determined
     */
    public function getCreatingUser(AbstractDBElement $element): ?User
    {
        return $this->getLastUser($element, ElementCreatedLogEntry::class);
    }

    protected function getLastUser(AbstractDBElement $element, string $class): ?User
    {
        $qb = $this->createQueryBuilder('log');
        $qb->select('log')
            //->where('log INSTANCE OF App\Entity\LogSystem\ElementEditedLogEntry')
            ->where('log INSTANCE OF '.$class)
            ->andWhere('log.target_type = :target_type')
            ->andWhere('log.target_id = :target_id')
            ->orderBy('log.timestamp', 'DESC');

        $qb->setParameters([
            'target_type' => AbstractLogEntry::targetTypeClassToID(get_class($element)),
            'target_id' => $element->getID(),
        ]);

        $query = $qb->getQuery();
        $query->setMaxResults(1);
        /** @var AbstractLogEntry[] $results */
        $results = $query->execute();
        if (isset($results[0])) {
            return $results[0]->getUser();
        }

        return null;
    }
}
