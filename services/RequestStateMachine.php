<?php

final class RequestStateMachine {
    public const STATES = ['pending', 'processing', 'completed', 'rejected'];
    private const TRANSITIONS = [
        'pending'    => ['processing', 'completed', 'rejected'],
        'processing' => ['completed', 'rejected', 'pending'],
        'completed'  => ['processing'],
        'rejected'   => ['pending', 'processing'],
    ];

    public static function normalize(string $status): string { 
        $s = strtolower(trim($status));
        return match ($s) {
            'submitted', 'draft', 'needs_information', 'payment_required', 'requirements_review' => 'pending',
            'approved', 'scheduled', 'ready_for_release', 'under review', 'payment_review'       => 'processing',
            'cancelled', 'declined', 'declined / cancelled'                                      => 'rejected',
            default => in_array($s, self::STATES, true) ? $s : 'pending',
        };
    }

    public static function canTransition(string $from, string $to): bool { 
        $normTo = self::normalize($to);
        $normFrom = self::normalize($from);
        if ($normFrom === $normTo) {
            return true;
        }
        return in_array($normTo, self::TRANSITIONS[$normFrom] ?? [], true); 
    }

    public static function requiresReason(string $to): bool { 
        return self::normalize($to) === 'rejected'; 
    }

    public static function nextAction(string $status): array {
        return match (self::normalize($status)) {
            'pending'    => ['required' => true, 'label' => 'The parish office is reviewing your request and requirements.', 'action' => 'Wait for parish review or staff feedback.'],
            'processing' => ['required' => false, 'label' => 'Your request is currently being processed.', 'action' => 'Wait for the parish office to complete verification or document issuance.'],
            'completed'  => ['required' => false, 'label' => 'Request completed.', 'action' => 'No further action is required. Issued records are available.'],
            'rejected'   => ['required' => true, 'label' => 'Request rejected.', 'action' => 'Review the admin remarks and contact the parish office if correction is possible.'],
            default      => ['required' => true, 'label' => 'Pending review.', 'action' => 'Wait for staff review.'],
        };
    }
}
