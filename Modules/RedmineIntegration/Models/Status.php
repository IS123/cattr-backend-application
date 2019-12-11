<?php

namespace Modules\RedmineIntegration\Models;

class Status extends CompanyProperty
{
    protected const REDMINE_STATUSES = 'redmine_statuses';
    protected const REDMINE_ACTIVE_STATUS = 'redmine_active_status';
    protected const REDMINE_INACTIVE_STATUS = 'redmine_inactive_status';
    protected const REDMINE_ACTIVATE_ON_STATUSES = 'redmine_activate_on_statuses';
    protected const REDMINE_DEACTIVATE_ON_STATUSES = 'redmine_deactivate_on_statuses';

    /**
     * @var ClientFactory
     */
    protected $clientFactory;

    public function __construct(ClientFactory $clientFactory)
    {
        $this->clientFactory = $clientFactory;
    }

    public function getAll(): array
    {
        $property = $this->get(static::REDMINE_STATUSES);

        return isset($property) ? (json_decode($property->value, true) ?: []) : [];
    }

    public function setAll(array $value)
    {
        $this->set(static::REDMINE_STATUSES, json_encode($value));
    }

    public function existsByID(int $id): bool
    {
        $statuses = $this->getAll();
        foreach ($statuses as $status) {
            if ($status['id'] == $id) {
                return true;
            }
        }

        return false;
    }

    public function add(int $id, string $name, bool $isActive, bool $isClosed)
    {
        $statuses = $this->getAll();
        $statuses[] = [
            'id' => $id,
            'name' => $name,
            'is_active' => $isActive,
            'is_closed' => $isClosed,
        ];

        $this->setAll($statuses);
    }

    public function getActiveStatusID(): int
    {
        $property = $this->get(static::REDMINE_ACTIVE_STATUS);

        return isset($property) ? $property->value : 0;
    }

    public function setActiveStatusID(int $value)
    {
        $this->set(static::REDMINE_ACTIVE_STATUS, $value);
    }

    public function getInactiveStatusID(): int
    {
        $property = $this->get(static::REDMINE_INACTIVE_STATUS);

        return isset($property) ? $property->value : 0;
    }

    public function setInactiveStatusID(int $value)
    {
        $this->set(static::REDMINE_INACTIVE_STATUS, $value);
    }

    public function getActivateOnStatuses(): array
    {
        $property = $this->get(static::REDMINE_ACTIVATE_ON_STATUSES);

        return isset($property) ? (json_decode($property->value, true) ?: []) : [];
    }

    public function setActivateOnStatuses(array $value)
    {
        $this->set(static::REDMINE_ACTIVATE_ON_STATUSES, json_encode($value));
    }

    public function getDeactivateOnStatuses(): array
    {
        $property = $this->get(static::REDMINE_DEACTIVATE_ON_STATUSES);

        return isset($property) ? (json_decode($property->value, true) ?: []) : [];
    }

    public function setDeactivateOnStatuses(array $value)
    {
        $this->set(static::REDMINE_DEACTIVATE_ON_STATUSES, json_encode($value));
    }

    public function synchronize()
    {
        $client = $this->clientFactory->createCompanyClient();
        $redmineStatuses = $client->issue_status->all()['issue_statuses'];
        $savedStatuses = $this->getAll();

        // Merge statuses info from the redmine with the active state of stored statuses
        $statuses = array_map(function (array $redmineStatus) use ($savedStatuses) {
            // Try find saved status with the same ID
            $savedStatus = array_first($savedStatuses, function ($savedStatus) use ($redmineStatus) {
                return $savedStatus['id'] === $redmineStatus['id'];
            });

            // Set status is active, if saved status is exist and active,
            // or if status from the Redmine is not closed
            $redmineStatus['is_active'] = isset($savedStatus)
                ? $savedStatus['is_active']
                : !isset($redmineStatus['is_closed']);

            return $redmineStatus;
        }, $redmineStatuses);

        $this->setAll($statuses);
    }
}
