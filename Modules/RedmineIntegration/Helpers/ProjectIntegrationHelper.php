<?php

namespace Modules\RedmineIntegration\Helpers;

use App\Models\Project;
use App\Models\Property;
use App\Models\User;
use Exception;

class ProjectIntegrationHelper extends AbstractIntegrationHelper
{
    /**
     * Synchronize projects for all users
     */
    public function synchronizeProjects()
    {
        $users = User::all();

        foreach ($users as $user) {
            try {
                $this->synchronizeUserProjects($user->id);
            } catch (Exception $e) {
            }
        }
    }

    /**
     * Synchronize projects for current user
     *
     * @param int $userId User's id in our system
     *
     * @return array Associative array ['added_projects' => count_of_added_projects]
     * @throws Exception
     */
    public function synchronizeUserProjects(int $userId): array
    {
        try {
            $client = $this->clientFactory->createUserClient($userId);
            $projectsData = $client->project->all([
                'limit' => 1000
            ]);
            $projects = $projectsData['projects'];
            $addedProjectsCounter = 0;
            foreach ($projects as $projectFromRedmine) {
                //if project already exists => continue
                $projectExist = Property::where([
                    ['entity_type', '=', Property::PROJECT_CODE],
                    ['name', '=', 'REDMINE_ID'],
                    ['value', '=', $projectFromRedmine['id']]
                ])->first();

                if ($projectExist != null) {
                    continue;
                }

                $projectInfo = [
                    'company_id' => 4,
                    'name' => $projectFromRedmine['name'],
                    'description' => $projectFromRedmine['description']
                ];

                $project = Project::create($projectInfo);
                $addedProjectsCounter++;

                Property::create([
                    'entity_id' => $project->id,
                    'entity_type' => Property::PROJECT_CODE,
                    'name' => 'REDMINE_ID',
                    'value' => $projectFromRedmine['id']
                ]);
            }
            return [
                'added_projects' => $addedProjectsCounter
            ];
        } catch (Exception $e) {
            if ($e->getCode() == 404) {
                return [
                    'added_projects' => [],
                    'notice' => 'This user does not have assigned Redmine URL',
                ];
            }

            throw $e;
        }
    }
}
