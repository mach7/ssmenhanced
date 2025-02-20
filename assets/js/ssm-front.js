document.addEventListener('DOMContentLoaded', function () {
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
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    button.textContent = 'Added';
                    if (typeof data.cart_total !== 'undefined') {
                        const cartCounter = document.querySelector('.ssm-cart-counter');
                        if (cartCounter) {
                            cartCounter.textContent = data.cart_total;
                        }
                    }
                } else {
                    const errorMsg = data.data || 'An unexpected error occurred.';
                    console.error('Error adding product to cart:', errorMsg);
                    button.textContent = 'Error';
                    alert('Error: ' + errorMsg);
                }
            })
            .catch(function (error) {
                console.error('Network error while adding product to cart:', error);
                button.textContent = 'Error';
                alert('A network error occurred. Please try again.');
            })
            .finally(function () {
                setTimeout(function () {
                    button.disabled = false;
                    button.textContent = originalText;
                }, 2000);
            });
        });
    });
});
