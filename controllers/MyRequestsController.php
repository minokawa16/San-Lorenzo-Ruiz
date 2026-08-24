<?php

require_once dirname(__DIR__) . '/services/RequestListService.php';

/** HTTP adapter for the parishioner's request listing. */
final class MyRequestsController
{
    private $service;

    public function __construct(RequestListService $service)
    {
        $this->service = $service;
    }

    public function index(int $userId, array $query): array
    {
        return array_merge($this->service->getForUser($userId, $query), [
            'page_title' => 'My Requests',
            'breadcrumbs' => ['Dashboard' => 'index.php', 'My Requests' => null],
        ]);
    }
}
