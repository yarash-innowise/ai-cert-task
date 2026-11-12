<?php

declare(strict_types=1);

namespace App\Family\Domain;

/** Everything a child login might try: the allowed list and the refused list of AC-46 / AC-47. */
enum ChildAction: string
{
    // Allowed (AC-46)
    case BrowseEvents = 'browse_events';
    case ViewContent = 'view_content';
    case ViewProgress = 'view_progress';
    case SubmitFeedbackRequest = 'submit_feedback_request';
    case UpdateBasicProfile = 'update_basic_profile';
    case ViewTokens = 'view_tokens';
    case SwitchOwnContext = 'switch_own_context';

    // Refused (AC-47)
    case AddTrainer = 'add_trainer';
    case ManagePaymentMethods = 'manage_payment_methods';
    case PurchaseTokens = 'purchase_tokens';
    case CompletePurchase = 'complete_purchase';
    case DeleteAccount = 'delete_account';
    case ChangeTrainerAssociations = 'change_trainer_associations';
    case ViewParentData = 'view_parent_data';
}
