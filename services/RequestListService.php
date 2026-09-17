<?php

require_once dirname(__DIR__) . '/repositories/RequestRepository.php';

/** Validates filters and assembles the request-list view model. */
final class RequestListService
{
    public const ALLOWED_STATUSES = ['pending', 'processing', 'completed', 'rejected'];
    public const DROPDOWN_STATUSES = ['pending', 'processing', 'completed', 'rejected'];
    public const ALLOWED_TYPES = [
        'blessings' => 'Blessings',
        'certificates' => 'Certificates',
        'sacramental_services' => 'Sacramental Services',
    ];
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
        $type = trim((string) ($query['type'] ?? $query['request_type'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));

        if (!array_key_exists($type, self::ALLOWED_TYPES)) {
            $typeAliases = [
                'blessing' => 'blessings',
                'certificate' => 'certificates',
                'sacramental' => 'sacramental_services',
                'sacrament' => 'sacramental_services',
                'services' => 'sacramental_services',
            ];
            $type = $typeAliases[strtolower($type)] ?? '';
        }

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = '';
        }

        $offset = ($page - 1) * $limit;
        $result = $this->repository->findForUser(
            $userId,
            $search,
            $status,
            $type,
            $limit,
            $offset
        );
        $pagination = getPaginationData($page, $limit, $result['total']);

        return [
            'requests' => $result['items'],
            'pagination' => $pagination,
            'page' => $page,
            'search' => $search,
            'type_filter' => $type,
            'status_filter' => $status,
            'allowed_types' => self::ALLOWED_TYPES,
            'allowed_statuses' => self::ALLOWED_STATUSES,
            'dropdown_statuses' => self::DROPDOWN_STATUSES,
        ];
    }
}
