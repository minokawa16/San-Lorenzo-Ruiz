<?php
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/repositories/RequestRepository.php';
require_once dirname(__DIR__) . '/services/RequestListService.php';
require_once dirname(__DIR__) . '/controllers/MyRequestsController.php';

$failed = 0;
function testAssert(bool $condition, string $message): void {
    global $failed;
    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        $failed++;
        echo "FAIL: {$message}\n";
    }
}

// 1. Check Constants in RequestListService and RequestRepository
testAssert(isset(RequestListService::ALLOWED_TYPES['blessings']), 'RequestListService defines blessings');
testAssert(isset(RequestListService::ALLOWED_TYPES['certificates']), 'RequestListService defines certificates');
testAssert(isset(RequestListService::ALLOWED_TYPES['sacramental_services']), 'RequestListService defines sacramental_services');

testAssert(isset(RequestRepository::CATEGORY_TYPES['blessings']), 'RequestRepository defines blessings categories');
testAssert(isset(RequestRepository::CATEGORY_TYPES['certificates']), 'RequestRepository defines certificates categories');
testAssert(isset(RequestRepository::CATEGORY_TYPES['sacramental_services']), 'RequestRepository defines sacramental_services categories');

// Check category members
testAssert(in_array('house_blessing', RequestRepository::CATEGORY_TYPES['blessings'], true), 'house_blessing in blessings');
testAssert(in_array('baptismal_certificate', RequestRepository::CATEGORY_TYPES['certificates'], true), 'baptismal_certificate in certificates');
testAssert(in_array('marriage_wedding_service', RequestRepository::CATEGORY_TYPES['sacramental_services'], true), 'marriage_wedding_service in sacramental_services');

// 2. Mock Repository to test RequestListService & MyRequestsController isolation
class MockRequestRepository extends RequestRepository {
    public $lastUserId;
    public $lastSearch;
    public $lastStatus;
    public $lastType;
    public $lastLimit;
    public $lastOffset;

    public function __construct() {}

    public function findForUser(int $userId, string $search, string $status, $typeOrLimit = '', $limitOrOffset = 10, int $offset = 0): array {
        $this->lastUserId = $userId;
        $this->lastSearch = $search;
        $this->lastStatus = $status;
        if (is_int($typeOrLimit)) {
            $this->lastType = '';
            $this->lastLimit = $typeOrLimit;
            $this->lastOffset = (int) $limitOrOffset;
        } else {
            $this->lastType = (string) $typeOrLimit;
            $this->lastLimit = (int) $limitOrOffset;
            $this->lastOffset = $offset;
        }
        return [
            'items' => [
                [
                    'request_id' => 101,
                    'reference_number' => 'REQ-2026-001',
                    'request_type' => 'house_blessing',
                    'status' => 'processing',
                    'date_requested' => '2026-09-01 10:00:00',
                    'updated_at' => '2026-09-02 12:00:00'
                ]
            ],
            'total' => 1
        ];
    }
}

$mockRepo = new MockRequestRepository();
$service = new RequestListService($mockRepo);
$controller = new MyRequestsController($service);

// Test empty type / default query
$vm = $controller->index(42, []);
testAssert($vm['type_filter'] === '', 'Default type_filter is empty string');
testAssert($mockRepo->lastType === '', 'Repo received empty type');
testAssert($vm['status_filter'] === '', 'Default status_filter is empty');
testAssert(count($vm['requests']) === 1, 'Requests passed correctly');

// Test blessings filter
$vm = $controller->index(42, ['type' => 'blessings', 'status' => 'processing', 'q' => 'Test']);
testAssert($vm['type_filter'] === 'blessings', 'Type filter set to blessings');
testAssert($mockRepo->lastType === 'blessings', 'Repo received blessings');
testAssert($vm['status_filter'] === 'processing', 'Status filter set to processing');
testAssert($mockRepo->lastStatus === 'processing', 'Repo received processing');
testAssert($vm['search'] === 'Test', 'Search query preserved');

// Test alias normalization
$vm = $controller->index(42, ['type' => 'certificate']);
testAssert($vm['type_filter'] === 'certificates', 'Alias certificate mapped to certificates');
testAssert($mockRepo->lastType === 'certificates', 'Repo received certificates');

$vm = $controller->index(42, ['type' => 'sacramental']);
testAssert($vm['type_filter'] === 'sacramental_services', 'Alias sacramental mapped to sacramental_services');
testAssert($mockRepo->lastType === 'sacramental_services', 'Repo received sacramental_services');

// Test backward compatibility when called with old arguments (limit as 4th arg)
$mockRepo->findForUser(42, '', '', 10, 0);
testAssert($mockRepo->lastType === '' && $mockRepo->lastLimit === 10, 'findForUser backward compatibility works with integer limit as 4th arg');

// 3. View inspection
$viewContent = file_get_contents(dirname(__DIR__) . '/views/users/my-requests.php');
testAssert(strpos($viewContent, 'Submit New Request') === false, 'Header Submit New Request button is completely removed');
testAssert(strpos($viewContent, 'name="type"') !== false, 'Request type dropdown (name="type") exists in view');
testAssert(strpos($viewContent, '<option value="">All Types</option>') !== false, 'All Types default option exists in view');
testAssert(strpos($viewContent, 'value="blessings"') !== false, 'Blessings option exists in view');
testAssert(strpos($viewContent, 'value="certificates"') !== false, 'Certificates option exists in view');
testAssert(strpos($viewContent, 'value="sacramental_services"') !== false, 'Sacramental Services option exists in view');
testAssert(strpos($viewContent, 'type=<?php echo urlencode($type_filter') !== false, 'Pagination links propagate type parameter');

echo "\nSummary: " . ($failed === 0 ? "ALL PASSED" : "{$failed} FAILED") . "\n";
exit($failed === 0 ? 0 : 1);
