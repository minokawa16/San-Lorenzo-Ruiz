<?php
require_once dirname(__DIR__) . '/services/RequestStateMachine.php';
require_once dirname(__DIR__) . '/database/config.php';

$failed=0;
function reqCheck(bool $ok,string $label):void{global $failed;echo ($ok?'PASS: ':'FAIL: ').$label.PHP_EOL;if(!$ok)$failed++;}
reqCheck(RequestStateMachine::canTransition('submitted','requirements_review'),'submitted can enter requirements review');
reqCheck(!RequestStateMachine::canTransition('submitted','completed'),'workflow skipping to completed is denied');
reqCheck(!RequestStateMachine::canTransition('completed','cancelled'),'completed is terminal');
reqCheck(RequestStateMachine::requiresReason('rejected'),'rejection requires a reason');
reqCheck(RequestStateMachine::nextAction('needs_information')['required']===true,'next action is server-generated');
$tables=$conn->query("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('request_status_history','request_messages','request_internal_notes','request_assignments','request_idempotency_keys')")->fetch_assoc();
reqCheck((int)$tables['c']===5,'unified request support tables exist');
$enum=$conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='requests' AND COLUMN_NAME='status'")->fetch_assoc();
reqCheck(strpos($enum['COLUMN_TYPE'],'needs_information')!==false && strpos($enum['COLUMN_TYPE'],'ready_for_release')!==false,'required request states are stored');
echo "{$failed} failed request checks.".PHP_EOL; exit($failed?1:0);
