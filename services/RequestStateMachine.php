<?php

final class RequestStateMachine {
    public const STATES = ['draft','pending','submitted','requirements_review','needs_information','payment_required','payment_review','approved','scheduled','processing','ready_for_release','completed','rejected','cancelled'];
    private const TRANSITIONS = [
        'draft' => ['pending','submitted','cancelled'],
        'pending' => ['requirements_review','needs_information','approved','rejected','cancelled'],
        'submitted' => ['requirements_review','needs_information','approved','rejected','cancelled'],
        'requirements_review' => ['needs_information','payment_required','approved','rejected'],
        'needs_information' => ['pending','submitted','cancelled'],
        'payment_required' => ['payment_review','cancelled'],
        'payment_review' => ['approved','payment_required','rejected'],
        'approved' => ['scheduled','processing','cancelled'],
        'scheduled' => ['processing','cancelled'],
        'processing' => ['ready_for_release','completed','rejected'],
        'ready_for_release' => ['completed'],
        'completed' => [], 'rejected' => [], 'cancelled' => [],
    ];

    public static function normalize(string $status): string { 
        $s = strtolower(trim($status));
        return $s === 'submitted' ? 'pending' : $s; 
    }
    public static function canTransition(string $from, string $to): bool { 
        $normTo = self::normalize($to);
        $normFrom = self::normalize($from);
        return in_array($normTo, self::TRANSITIONS[$normFrom] ?? [], true) || in_array($to, self::TRANSITIONS[$normFrom] ?? [], true); 
    }
    public static function requiresReason(string $to): bool { return in_array($to, ['rejected','needs_information','cancelled'], true); }
    public static function nextAction(string $status): array {
        return match (self::normalize($status)) {
            'pending','submitted','requirements_review' => ['required'=>true,'label'=>'The parish office is reviewing your requirements.','action'=>'Wait for review or respond to any request for information.'],
            'needs_information' => ['required'=>true,'label'=>'Additional information is required.','action'=>'Upload the requested information or send a message.'],
            'payment_required' => ['required'=>true,'label'=>'Payment is required.','action'=>'Submit payment through this request.'],
            'payment_review' => ['required'=>false,'label'=>'Payment is under review.','action'=>'Wait for payment verification.'],
            'approved','scheduled' => ['required'=>true,'label'=>'Your request is approved.','action'=>'Review the scheduled date or wait for processing.'],
            'processing' => ['required'=>false,'label'=>'Your request is being processed.','action'=>'Wait for the parish office to complete processing.'],
            'ready_for_release' => ['required'=>true,'label'=>'Your request is ready for release.','action'=>'Claim or collect the requested document/service.'],
            'completed' => ['required'=>false,'label'=>'Request completed.','action'=>'No further action is required.'],
            'rejected' => ['required'=>true,'label'=>'Request rejected.','action'=>'Review the reason and contact the parish office if correction is possible.'],
            'cancelled' => ['required'=>false,'label'=>'Request cancelled.','action'=>'No further action is required.'],
            default => ['required'=>true,'label'=>'Draft request.','action'=>'Complete and submit this request.'],
        };
    }
}
