<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Pterodactyl\Models\Task;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Schedule;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Permission;
use Pterodactyl\Repositories\Eloquent\TaskRepository;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;
use Pterodactyl\Transformers\Api\Client\TaskTransformer;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\Schedules\StoreTaskRequest;

class ScheduleTaskController extends ClientApiController
{
    /**
     * @var \Pterodactyl\Repositories\Eloquent\TaskRepository
     */
    private $repository;

    /**
     * ScheduleTaskController constructor.
     *
     * @param \Pterodactyl\Repositories\Eloquent\TaskRepository $repository
     */
    public function __construct(TaskRepository $repository)
    {
        parent::__construct();

        $this->repository = $repository;
    }

    /**
     * Create a new task for a given schedule and store it in the database.
     *
     * @param \Pterodactyl\Http\Requests\Api\Client\Servers\Schedules\StoreTaskRequest $request
     * @param \Pterodactyl\Models\Server $server
     * @param \Pterodactyl\Models\Schedule $schedule
     * @return array
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function store(StoreTaskRequest $request, Server $server, Schedule $schedule)
    {
        if ($schedule->server_id !== $server->id) {
            throw new NotFoundHttpException;
        }

        $lastTask = $schedule->tasks->last();

        /** @var \Pterodactyl\Models\Task $task */
        $task = $this->repository->create([
            'schedule_id' => $schedule->id,
            'sequence_id' => ($lastTask->sequence_id ?? 0) + 1,
            'action' => $request->input('action'),
            'payload' => $request->input('payload') ?? '',
            'time_offset' => $request->input('time_offset'),
        ]);

        return $this->fractal->item($task)
            ->transformWith($this->getTransformer(TaskTransformer::class))
            ->toArray();
    }

    /**
     * Updates a given task for a server.
     *
     * @param \Pterodactyl\Http\Requests\Api\Client\Servers\Schedules\StoreTaskRequest $request
     * @param \Pterodactyl\Models\Server $server
     * @param \Pterodactyl\Models\Schedule $schedule
     * @param \Pterodactyl\Models\Task $task
     * @return array
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function update(StoreTaskRequest $request, Server $server, Schedule $schedule, Task $task)
    {
        if ($schedule->id !== $task->schedule_id || $server->id !== $schedule->server_id) {
            throw new NotFoundHttpException;
        }

        $this->repository->update($task->id, [
            'action' => $request->input('action'),
            'payload' => $request->input('payload') ?? '',
            'time_offset' => $request->input('time_offset'),
        ]);

        return $this->fractal->item($task->refresh())
            ->transformWith($this->getTransformer(TaskTransformer::class))
            ->toArray();
    }

    /**
     * Determines if a user can delete the task for a given server.
     *
     * @param \Pterodactyl\Http\Requests\Api\Client\ClientApiRequest $request
     * @param \Pterodactyl\Models\Server $server
     * @param \Pterodactyl\Models\Schedule $schedule
     * @param \Pterodactyl\Models\Task $task
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(ClientApiRequest $request, Server $server, Schedule $schedule, Task $task)
    {
        if ($task->schedule_id !== $schedule->id || $schedule->server_id !== $server->id) {
            throw new NotFoundHttpException;
        }

        if (! $request->user()->can(Permission::ACTION_SCHEDULE_UPDATE, $server)) {
            throw new HttpForbiddenException('You do not have permission to perform this action.');
        }

        $this->repository->delete($task->id);

        return JsonResponse::create(null, Response::HTTP_NO_CONTENT);
    }
}
