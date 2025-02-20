document.addEventListener('DOMContentLoaded', function () {
    // --- Add to Cart functionality ---
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
                // WordPress returns { success: bool, data: {...} }
                if (json.success) {
                    // "cart_total" is inside json.data
                    const result = json.data;
                    button.textContent = 'Added';
                    if (typeof result.cart_total !== 'undefined') {
                        const cartCounter = document.querySelector('.ssm-cart-counter');
                        if (cartCounter) {
                            cartCounter.textContent = result.cart_total;
                        }
                    }
                } else {
                    // "json.data" might hold an error message
                    const errorMsg = (json.data) ? json.data : 'An unexpected error occurred.';
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
            // WordPress returns { success: bool, data: {...} }
            if (json.success) {
                const result = json.data;
                // Update the input field
                qtyElement.value = newQuantity;

                // Update product subtotal in the current row
                const row = qtyElement.closest('tr');
                const subtotalCell = row.querySelector('.ssm-subtotal');
                if (subtotalCell && typeof result.product_subtotal !== 'undefined') {
                    subtotalCell.textContent = '$' + parseFloat(result.product_subtotal).toFixed(2);
                }

                // Update overall total
                const totalDisplay = document.querySelector('.ssm-total');
                if (totalDisplay && typeof result.total_price !== 'undefined') {
                    totalDisplay.textContent = 'Total: $' + parseFloat(result.total_price).toFixed(2);
                }
            } else {
                const errorMsg = (json.data) ? json.data : 'Failed to update quantity.';
                console.error('Error updating quantity:', errorMsg);
                alert('Error: ' + errorMsg);
            }
        })
        .catch(error => {
            console.error('Network error while updating quantity:', error);
            alert('A network error occurred. Please try again.');
        });
    }

    // Plus buttons
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

    // Minus buttons
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

    // Manual changes to quantity input
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
