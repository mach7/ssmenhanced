/**
 * ssm-front.js
 * Production-ready script for handling "Add to Cart" functionality.
 *
 * Requirements:
 * - A global JavaScript object "ssm_params" with the following property:
 *    - ajax_url: URL to admin-ajax.php (e.g., provided via wp_localize_script)
 *
 * This script listens for clicks on buttons with the class "ssm-add-to-cart-btn",
 * sends a POST request with the product_id to the AJAX endpoint using the action "ssm_add_to_cart",
 * and updates the button state based on the response.
 */

document.addEventListener('DOMContentLoaded', function () {
    // Get all "Add to Cart" buttons on the page.
    const addToCartButtons = document.querySelectorAll('.ssm-add-to-cart-btn');

    addToCartButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();

            // Find the nearest parent element that holds product data.
            const productElement = button.closest('.ssm-product');
            if (!productElement) {
                console.error('Product container element not found.');
                return;
            }

            // Retrieve the product ID from the data attribute.
            const productId = productElement.getAttribute('data-product-id');
            if (!productId) {
                console.error('Product ID not found in the data attributes.');
                return;
            }

            // Provide immediate UI feedback.
            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = 'Adding...';

            // Prepare the data to send via AJAX.
            const formData = new FormData();
            formData.append('action', 'ssm_add_to_cart');
            formData.append('product_id', productId);

            // Send the AJAX request.
            fetch(ssm_params.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    // On success, update UI accordingly.
                    button.textContent = 'Added';
                    // If your response includes updated cart info (e.g., cart_total), update it here.
                    if (typeof data.cart_total !== 'undefined') {
                        const cartCounter = document.querySelector('.ssm-cart-counter');
                        if (cartCounter) {
                            cartCounter.textContent = data.cart_total;
                        }
                    }
                } else {
                    // Handle server-reported error.
                    console.error('Error adding product to cart:', data.data);
                    button.textContent = 'Error';
                    alert('Error: ' + data.data);
                }
            })
            .catch(function (error) {
                // Handle network or unexpected errors.
                console.error('Network error while adding product to cart:', error);
                button.textContent = 'Error';
                alert('A network error occurred. Please try again.');
            })
            .finally(function () {
                // Re-enable the button after a brief delay.
                setTimeout(function () {
                    button.disabled = false;
                    button.textContent = originalText;
                }, 2000);
            });
        });
    });
});
