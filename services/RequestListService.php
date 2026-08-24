<?php

require_once dirname(__DIR__) . '/repositories/RequestRepository.php';

/** Validates filters and assembles the request-list view model. */
final class RequestListService
{
    public const ALLOWED_STATUSES = ['pending', 'approved', 'processing', 'completed', 'rejected'];
    private $repository;

    public function __construct(RequestRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getForUser(int $userId, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = 10;
        $search = trim((string) ($query['q'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = '';
        }

        $offset = ($page - 1) * $limit;
        $result = $this->repository->findForUser(
            $userId,
            $search,
            $status,
            $limit,
            $offset
        );
        $pagination = getPaginationData($page, $limit, $result['total']);

        return [
            'requests' => $result['items'],
            'pagination' => $pagination,
            'page' => $page,
            'search' => $search,
            'status_filter' => $status,
            'allowed_statuses' => self::ALLOWED_STATUSES,
        ];
    }
}
