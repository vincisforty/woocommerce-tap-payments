/**
 * Tap Payment Gateway Block Integration
 */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element;
const { __ } = window.wp.i18n;

// Get payment method data
const tapPaymentData = window.tapPaymentBlocksData || {};

/**
 * Content component for the payment method
 */
const TapPaymentContent = () => {
    return createElement(
        'div',
        { className: 'tap-payment-content' },
        createElement(
            'p',
            null,
            tapPaymentData.description || __('Pay securely with Tap Payment Gateway', 'tap-payment')
        )
    );
};

/**
 * Label component for the payment method
 */
const TapPaymentLabel = () => {
    return createElement(
        'span',
        { className: 'wc-block-components-payment-method-label' },
        tapPaymentData.title || __('Tap Payment', 'tap-payment')
    );
};

/**
 * Payment method configuration
 */
const tapPaymentMethod = {
    name: 'tap_payment',
    label: createElement(TapPaymentLabel),
    content: createElement(TapPaymentContent),
    edit: createElement(TapPaymentContent),
    canMakePayment: () => true,
    ariaLabel: tapPaymentData.title || __('Tap Payment', 'tap-payment'),
    supports: {
        features: tapPaymentData.supports || ['products']
    }
};

// Register the payment method
registerPaymentMethod(tapPaymentMethod);