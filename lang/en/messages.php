<?php

/*
 * Custom flash / error strings surfaced to users (storefront + admin), resolved
 * through __('messages.*') so they follow the request locale. Mirror ar/messages.php.
 */

return [

    'cart' => [
        'added' => 'Added to cart',
        'empty' => 'Your cart is empty.',
        'option_required' => 'Please choose an option first.',
        'option_unavailable' => 'That option is no longer available.',
        'coupon_applied' => 'Coupon applied.',
        'coupon_removed' => 'Coupon removed.',
    ],

    /*
     * Coupon errors are shown to the SHOPPER (cart page + checkout), so they must
     * be localized — they were hard-coded English literals until 2026-08-06.
     */
    'checkout' => [
        'coupon_invalid' => 'This coupon code is invalid or has expired.',
        'coupon_not_yours' => 'This coupon is not available for your account.',
        'coupon_used_up' => 'You have already used this coupon the maximum number of times.',
    ],

    'review' => [
        'posted' => 'Your review has been posted. Thank you!',
        'reward_issued' => 'Thanks for your review! Your :percent% discount code :code is on your account, valid for 30 days.',
        'only_purchased' => 'You can only review products you have purchased.',
        'no_self_vote' => 'You can’t vote on your own review.',
        'anonymous' => 'Customer',
    ],

    'payment' => [
        'already_settled' => 'That order has already been paid or closed — nothing left to do.',
        'init_failed' => 'Could not start online payment. You can pay by bank transfer.',
        'received' => 'Payment received. Thank you.',
        'not_completed' => 'Your payment was not completed. You can try again below.',
        'result_unknown' => 'We could not match that payment to an order. If you were charged, contact us and we will sort it out.',
    ],

    'orders' => [
        'cancelled' => 'Your order has been cancelled. Any payment will be returned to you.',
        'not_cancellable' => 'This order can no longer be cancelled. Contact us and we will help.',
    ],

    'profile' => [
        'password_set' => 'Password set. You can now sign in with your email and password.',
        'email_needed_first' => 'Add your email address first, so you have something to sign in with.',
        'updated' => 'Your details have been updated.',
        'password_updated' => 'Your password has been changed.',
    ],

    'errors' => [
        'page_expired' => 'The page expired. Please try again.',
        'too_many_requests' => 'Too many attempts in a short time. Please wait a moment and try again.',
    ],

    'security' => [
        'verify_failed' => 'We couldn’t verify you’re not a robot. Please try again.',
        'reset_not_available' => 'This address cannot receive email, so no reset link can be sent to it. Staff passwords are reset by an administrator from the admin panel.',
    ],

    'otp' => [
        'rate_limited' => 'Please wait a moment before requesting a new code.',
        'invalid' => 'The code is incorrect or has expired.',
        'unavailable' => 'WhatsApp sign-in is unavailable right now. You can sign in with your email and password instead.',
    ],

    'requests' => [
        'received' => 'Thanks! We’ve noted your interest and will let you know as soon as it’s available.',
    ],

    'contact' => [
        'received' => 'Thanks for reaching out! Your message has arrived and we’ll get back to you soon.',
    ],

    'returns' => [
        'filed' => 'Your return request was received. We will review it and get back to you soon.',
        'not_yours' => 'This order does not belong to you.',
        'not_delivered' => 'Returns can only be requested after delivery.',
        'window_expired' => 'The return window (3 days from delivery) has expired.',
        'already_filed' => 'A return request already exists for this order.',
        'no_items' => 'Select at least one item to return.',
        'invalid_items' => 'The return items are invalid.',
        'invalid_transition' => 'This action is not allowed in the return’s current state.',
    ],

    'marketing' => [
        'template_saved' => 'Template saved.',
        'campaign_queued' => 'Campaign queued for sending.',
        'already_sent' => 'This campaign was already sent.',
        'template_not_approved' => 'Only Meta-approved templates can be sent.',
        'no_audience' => 'No customers are opted in to the marketing list.',
        'params_mismatch' => 'The number of variables does not match the template.',
    ],

    'admin' => [
        'settings_saved' => 'Settings saved.',
        'content_reset' => 'Content restored to the project-handover defaults.',
        'no_permission' => 'You do not have permission to perform this action.',
        'permissions_updated' => 'Permissions updated for :name.',
        'editor_created' => 'Editor account created for :name.',
        'admin_created' => 'Admin account created for :name.',
        'editor_deleted' => 'Editor account removed.',
        'role_promoted' => ':name is now an admin with full access.',
        'role_demoted' => ':name is now an editor. Set their permissions below.',
        'role_self' => 'You cannot change your own role.',
        'role_last_admin' => 'This is the only admin account. Promote someone else before changing this one.',
        'password_reset_for' => 'New password set for :name. Send it to them, then ask them to keep it safe.',
        'password_reset_self' => 'To change your own password, use the key icon in the top bar — it asks for your current password.',
        'password_reset_admin_only' => 'Only an admin can reset another admin’s password.',
        'page_saved' => 'Page saved.',
        'review_saved' => 'Review saved.',
        'review_deleted' => 'Review deleted.',
        // Store events — named, time-boxed homepage campaigns.
        'store_event_saved' => 'Event saved.',
        'store_event_deleted' => 'Event deleted.',
        'store_event_paused' => 'Event paused. It is off the homepage until you switch it back on.',
        'store_event_resumed' => 'Event switched on.',
        'store_event_offer_added' => 'Offer added to the event.',
        'store_event_offer_saved' => 'Offer updated.',
        'store_event_offer_removed' => 'Offer removed from the event.',
        'store_event_offer_created' => 'Offer created and added to the event. It leaves the store on its own when the event ends.',
        'store_event_banner_added' => 'Hero banner added.',
        'store_event_banner_saved' => 'Hero banner updated.',
        'store_event_banner_removed' => 'Hero banner removed.',
        'coupon_saved' => 'Coupon saved.',
        'coupon_deleted' => 'Coupon deleted.',
        'coupon_activated' => 'Coupon activated.',
        'coupon_deactivated' => 'Coupon deactivated.',
        'coupon_has_redemptions' => 'This coupon has already been used, so it cannot be deleted. Deactivate it instead.',
        'reviews_imported' => ':count review(s) imported.',
        'return_approved' => 'Return request approved.',
        'return_rejected' => 'Return request rejected.',
        'return_exchanged' => 'Return closed as an exchange.',
        'return_refunded' => 'Refund executed and return closed.',
        'order_confirmed' => 'Order confirmed and stock deducted.',
        'order_unavailable' => 'Order marked unavailable and the payment hold released.',
        'shipment_created' => 'Shipment created and carrier pickup requested.',
        'payment_link_sent' => 'Payment link sent to the customer on WhatsApp.',
        'payment_link_failed' => 'Could not send the payment link — check the customer has a reachable phone number.',
        'payment_link_not_applicable' => 'This order is not waiting on a gateway payment, so there is nothing to resume.',
        'shipment_cancelled' => 'Shipment cancelled. The order is back to confirmed and can be shipped again.',
        'shipment_already_exists' => 'This order already has a shipment.',
        'shipment_missing' => 'This order has no shipment to cancel.',
        'no_delivery_options' => 'No delivery options are available for this destination.',
        'delivery_option_unavailable' => 'That carrier is no longer available for this order. Reopen the carrier list and choose again.',
        // Shipping carriers portal. `carrier_last_enabled` is the one that prevents
        // a silent failure days later: with every carrier off, the next shipment
        // fails at resolveOption() with nothing pointing back to this page.
        'carriers_refreshed' => 'Carrier list refreshed from OTO.',
        'carrier_enabled' => ':name is on. It can now be chosen when shipping an order.',
        'carrier_disabled' => ':name is off. It will no longer be offered or auto-selected when shipping.',
        'carrier_last_enabled' => 'This is the only carrier still available, so switching it off would leave no way to ship an order. Turn another one on first.',
        'carrier_saved' => 'Saved the details for :name.',
        'order_cancelled' => 'Order cancelled.',
        'images_uploaded' => 'Images uploaded.',
        'image_deleted' => 'Image deleted.',
        'primary_image_set' => 'Primary image set.',
        'product_created' => 'Product added.',
        'product_updated' => 'Product updated.',
        'product_deleted' => 'Product deleted.',
        'product_activated' => 'Product is now visible on the store.',
        'product_deactivated' => 'Product hidden from the store.',
        'product_needs_image' => 'A product must have at least one image.',
        // The publish guard: what a product still needs before it can be shown.
        'product_publish_blocked' => 'Saved, but this product stays hidden until it has: :missing.',
        'product_hidden_no_image' => 'Last image removed, so this product is now hidden until you add one.',
        'publish_requirement' => [
            'separator' => ', ',
            'price' => 'a price',
            'name_ar' => 'an Arabic name',
            'name_en' => 'an English name',
            'image' => 'at least one image',
        ],
        'message_handled' => 'Message marked as handled.',
        'request_handled' => 'Request marked as handled.',
        'review_hidden' => 'Review hidden from the storefront.',
        'review_shown' => 'Review is visible on the storefront again.',
        'review_deleted' => 'Review deleted.',
        'import_expired' => 'The import file has expired. Please upload it again.',
        'import_applied' => 'Stock updated: :count products.',
        'import_undone' => 'Import undone and previous stock restored.',
        'import_open_failed' => 'Could not open the uploaded file.',
        'import_empty' => 'The file is empty.',
        'discount_applied' => 'Discount applied to :count products.',
        'discount_cleared' => 'Discount cleared from :count products.',
        'discount_undone' => 'Discount change undone.',
        'discount_cannot_undo' => 'This discount change can no longer be undone.',
        'discount_none_to_clear' => 'No matching discounted products to clear.',
        'discount_import_columns' => 'The file needs a sku column and a discount_percent column.',
        'free_shipping_saved' => 'Free shipping settings saved.',
        'review_reward_saved' => 'Review reward settings saved.',
        'change_reverted' => 'Change reverted.',
        'change_revert_conflict' => 'Cannot revert: :fields changed again after this entry. Review the current values instead.',
        'change_revert_blocked' => 'This change can no longer be reverted.',
        'bulk_reverted' => 'Reverted :count of :total selected entries.',
        'bulk_revert_partial' => ':blocked blocked by a later change, :failed could not be reverted.',
        'bulk_revert_none' => 'Nothing was reverted. Later changes have to be undone first.',
        'bulk_logs_deleted' => 'Deleted :count log entries.',
        'bulk_delete_skipped_imports' => ':count stock imports were kept, so the Inventory page can still undo them.',
        'bulk_delete_none' => 'Nothing was deleted. Stock imports can only be undone from the Inventory page.',
    ],

];
