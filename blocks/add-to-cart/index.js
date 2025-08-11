(function(wp){
  const { registerBlockType } = wp.blocks;
  const { InspectorControls, useBlockProps, ServerSideRender } = wp.blockEditor || wp.editor;
  const { PanelBody, SelectControl, Spinner } = wp.components;
  const { useState, useEffect } = wp.element;
  const apiFetch = (wp.apiFetch) ? wp.apiFetch : null;

  registerBlockType('ssm/add-to-cart', {
    edit: (props) => {
      const { attributes, setAttributes } = props;
      const blockProps = useBlockProps();
      const [products, setProducts] = useState([]);
      const [isLoading, setIsLoading] = useState(true);
      const selected = attributes.productId || 0;

      useEffect(() => {
        let isMounted = true;
        if (!apiFetch) {
          setIsLoading(false);
          return;
        }
        apiFetch({ path: '/ssm/v1/products' })
          .then((items) => {
            if (!isMounted) return;
            setProducts(Array.isArray(items) ? items : []);
            setIsLoading(false);
          })
          .catch(() => { if (isMounted) setIsLoading(false); });
        return () => { isMounted = false; };
      }, []);

      const options = [{ label: 'Select a product…', value: 0 }].concat(
        products.map((p) => ({ label: p.name + ' (#' + p.id + ')', value: p.id }))
      );

      return (
        wp.element.createElement('div', blockProps, [
          wp.element.createElement(InspectorControls, {},
            wp.element.createElement(PanelBody, { title: 'Settings' },
              isLoading
                ? wp.element.createElement(Spinner)
                : wp.element.createElement(SelectControl, {
                    label: 'Product',
                    value: selected,
                    options: options,
                    onChange: (val) => setAttributes({ productId: parseInt(val || 0, 10) || 0 })
                  })
            )
          ),
          // Editor preview (optional)
          selected > 0 && ServerSideRender
            ? wp.element.createElement(ServerSideRender, { block: 'ssm/add-to-cart', attributes: { productId: selected } })
            : wp.element.createElement('div', {}, 'Select a product in block settings to preview…')
        ])
      );
    },
    save: () => null
  });
})(window.wp);


