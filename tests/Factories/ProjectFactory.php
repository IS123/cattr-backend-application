<?php

namespace Tests\Factories;

use App\Models\Project;
use Tests\Facades\TaskFactory;
use Faker\Factory as FakerFactory;

/**
 * Class ProjectFactory
 */
class ProjectFactory extends AbstractFactory
{
    private const COMPANY_ID = 1;
    private const DESCRIPTION_LENGTH = 300;

    /**
     * @var int
     */
    private $needsTasks = 0;

    /**
     * @var int
     */
    private $needsIntervals = 0;

    /**
     * @var array
     */
    private $users = 0;

    /**
     * @param int $quantity
     * @return self
     */
    public function withTasks(int $quantity = 1): self
    {
        $this->needsTasks = $quantity;

        return $this;
    }

    /**
     * @param array $attributes
     * @return Project
     */
    public function create(array $attributes = []): Project
    {
        $projectData = $this->getRandomProjectData();

        if ($attributes) {
            $projectData = array_merge($projectData, $attributes);
        }

        $project = Project::create($projectData);

        if ($this->users) {
            foreach ($this->users as $user) {
                $project->users()->attach($user->id);
            }
        }

        if ($this->needsTasks) {
            $this->createTasks($project);
        }

        if ($this->timestampsHidden) {
            $this->hideTimestamps($project);
        }

        return $project;
    }

    /**
     * @param $users
     * @return $this
     */
    public function forUsers($users): self
    {
        $this->users = $users;

        return $this;
    }

    /**
     * @return array
     */
    public function getRandomProjectData(): array
    {
        $faker = FakerFactory::create();

        return [
            'company_id' => self::COMPANY_ID,
            'name' => $faker->company,
            'description' => $faker->text(self::DESCRIPTION_LENGTH),
        ];
    }

    /**
     * @param Project $project
     */
    protected function createTasks(Project $project): void
    {
        do {
            TaskFactory::withIntervals($this->needsIntervals)
                ->forProject($project)
                ->create();
        } while (--$this->needsTasks);
    }
}
