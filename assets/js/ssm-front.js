document.addEventListener('DOMContentLoaded', function () {
    // --- Initialize Stripe Payment Form ---
    const cardElementContainer = document.getElementById('card-element');
    let stripe, elements, cardElement;
    if (cardElementContainer) {
        const stripePublicKey = (typeof ssm_params.publishableKey !== 'undefined')
            ? ssm_params.publishableKey
            : '';
        if (stripePublicKey) {
            stripe = Stripe(stripePublicKey);
            elements = stripe.elements();
            cardElement = elements.create('card');
            cardElement.mount(cardElementContainer);
        }
    }

    // Handle Stripe form submission
    const stripeForm = document.getElementById('ssm-stripe-form');
    if (stripeForm) {
        stripeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!stripe) {
                alert('Stripe is not initialized. Check your public key.');
                return;
            }
            const totalEl = document.getElementById('ssm-total-amount');
            // Remove any non-numeric characters (like $ or whitespace)
            const rawAmount = totalEl ? totalEl.textContent : '';
            const amount = parseFloat(rawAmount.replace(/[^0-9.]/g, ''));
            console.log("Calculated amount:", amount);
            if (!amount || amount <= 0) {
                alert("Invalid amount calculated: " + amount);
                return;
            }
            // Ensure the card Element is mounted (guard against DOM changes)
            const container = document.getElementById('card-element');
            if (!stripe || !elements) {
                alert('Stripe is not initialized. Check your public key.');
                return;
            }
            if (!container) {
                alert('Payment field is not available. Please reload the page.');
                return;
            }
            if (!cardElement || container.childElementCount === 0) {
                try {
                    cardElement = elements.create('card');
                    cardElement.mount(container);
                } catch (e) {
                    console.error('Failed to mount card element on submit:', e);
                    alert('Payment field could not be initialized. Please reload the page.');
                    return;
                }
            }
            const formData = new FormData();
            formData.append('action', 'ssm_create_payment_intent');
            formData.append('amount', amount);
            const nameInput = document.getElementById('ssm-customer-name');
            const emailInput = document.getElementById('ssm-customer-email');
            if (nameInput && emailInput) {
                formData.append('ssm_customer_name', nameInput.value);
                formData.append('ssm_customer_email', emailInput.value);
            }
            fetch(ssm_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(res => res.json())
            .then(json => {
                if (!json.success) {
                    throw new Error(json.data || 'Error creating PaymentIntent.');
                }
                const clientSecret = json.data.client_secret;
                return stripe.confirmCardPayment(clientSecret, {
                    payment_method: {
                        card: cardElement,
                    }
                });
            })
            .then(result => {
                if (result.error) {
                    console.error('Payment error:', result.error.message);
                    const errorDiv = document.getElementById('card-errors');
                    if (errorDiv) {
                        errorDiv.textContent = result.error.message;
                    }
                } else {
                    if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                        // Finalize on server: verify PaymentIntent and trigger post-payment processing
                        const finalize = new FormData();
                        finalize.append('action', 'ssm_finalize_payment');
                        finalize.append('payment_intent_id', result.paymentIntent.id);
                        fetch(ssm_params.ajax_url, { method: 'POST', credentials: 'same-origin', body: finalize })
                          .then(r => r.json())
                          .then(json => {
                              if (!json.success) {
                                  throw new Error(json.data || 'Finalize failed');
                              }
                              alert('Payment successful! Thank you.');
                              // Optionally redirect
                              // window.location.href = '/thank-you';
                          })
                          .catch(err => {
                              console.error('Finalize error:', err);
                              const errorDiv2 = document.getElementById('card-errors');
                              if (errorDiv2) { errorDiv2.textContent = err.message; }
                          });
                    }
                }
            })
            .catch(err => {
                console.error('Stripe payment error:', err);
                const errorDiv = document.getElementById('card-errors');
                if (errorDiv) {
                    errorDiv.textContent = err.message;
                }
            });
        });
    }

    // --- Add to Cart Functionality ---
    const addToCartButtons = document.querySelectorAll('.ssm-add-to-cart-btn');
    addToCartButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            const productElement = button.closest('.ssm-product');
            if (!productElement) {
                console.error('Product container element not found.');
                return;
            }
            const productId = productElement.getAttribute('data-product-id');
            if (!productId) {
                console.error('Product ID not found in the data attributes.');
                return;
            }
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Adding...';
            const formData = new FormData();
            formData.append('action', 'ssm_add_to_cart');
            formData.append('product_id', productId);
            fetch(ssm_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(response => response.json())
            .then(json => {
                if (json.success) {
                    const result = json.data;
                    button.textContent = 'Added';
                    if (typeof result.cart_total !== 'undefined') {
                        const cartCounter = document.querySelector('.ssm-cart-counter');
                        if (cartCounter) {
                            cartCounter.textContent = result.cart_total;
                        }
                    }
                } else {
                    const errorMsg = json.data ? json.data : 'An unexpected error occurred.';
                    console.error('Error adding product to cart:', errorMsg);
                    button.textContent = 'Error';
                    alert('Error: ' + errorMsg);
                }
            })
            .catch(error => {
                console.error('Network error while adding product to cart:', error);
                button.textContent = 'Error';
                alert('A network error occurred. Please try again.');
            })
            .finally(() => {
                setTimeout(() => {
                    button.disabled = false;
                    button.textContent = originalText;
                }, 2000);
            });
        });
    });

    // --- Quantity Control Functionality for Checkout Page ---
    function updateQuantity(productId, newQuantity, qtyElement) {
        const formData = new FormData();
        formData.append('action', 'ssm_update_cart_quantity');
        formData.append('product_id', productId);
        formData.append('quantity', newQuantity);
        fetch(ssm_params.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then(json => {
            if (json.success) {
                const result = json.data;
                qtyElement.value = newQuantity;
                const row = qtyElement.closest('tr');
                const subtotalCell = row.querySelector('.ssm-subtotal');
                if (subtotalCell && typeof result.product_subtotal !== 'undefined') {
                    subtotalCell.textContent = '$' + parseFloat(result.product_subtotal).toFixed(2);
                }
                const totalSpan = document.getElementById('ssm-total-amount');
                if (totalSpan && typeof result.total_price !== 'undefined') {
                    totalSpan.textContent = parseFloat(result.total_price).toFixed(2);
                }
            } else {
                const errorMsg = json.data ? json.data : 'Failed to update quantity.';
                console.error('Error updating quantity:', errorMsg);
                alert('Error: ' + errorMsg);
            }
        })
        .catch(error => {
            console.error('Network error while updating quantity:', error);
            alert('A network error occurred. Please try again.');
        });
    }
    const plusButtons = document.querySelectorAll('.ssm-qty-plus');
    plusButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const productId = button.getAttribute('data-product-id');
            const row = button.closest('tr');
            const qtyInput = row.querySelector('.ssm-qty-input');
            let currentQty = parseInt(qtyInput.value) || 1;
            currentQty++;
            updateQuantity(productId, currentQty, qtyInput);
        });
    });
    const minusButtons = document.querySelectorAll('.ssm-qty-minus');
    minusButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const productId = button.getAttribute('data-product-id');
            const row = button.closest('tr');
            const qtyInput = row.querySelector('.ssm-qty-input');
            let currentQty = parseInt(qtyInput.value) || 1;
            if (currentQty > 1) {
                currentQty--;
                updateQuantity(productId, currentQty, qtyInput);
            }
        });
    });
    const qtyInputs = document.querySelectorAll('.ssm-qty-input');
    qtyInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            const productId = input.getAttribute('data-product-id');
            let newQty = parseInt(input.value) || 1;
            if (newQty < 1) newQty = 1;
            updateQuantity(productId, newQty, input);
        });
    });

    // --- Copy Shortcode Functionality ---
    const copyButtons = document.querySelectorAll('.ssm-copy-btn');
    copyButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            const shortcode = e.target.getAttribute('data-shortcode');
            navigator.clipboard.writeText(shortcode).then(function() {
                e.target.innerText = 'Copied!';
                setTimeout(function() {
                    e.target.innerText = 'Copy';
                }, 2000);
            });
        });
    });
});
