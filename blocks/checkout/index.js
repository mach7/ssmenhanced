(function(wp){
  const { registerBlockType } = wp.blocks;
  const { useBlockProps } = wp.blockEditor || wp.editor;
  registerBlockType('ssm/checkout', {
    edit: () => {
      const blockProps = useBlockProps();
      return wp.element.createElement('div', blockProps, 'SSM Checkout block (frontend renders the checkout UI).');
    },
    save: () => null
  });
})(window.wp);


