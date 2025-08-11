(function(wp){
  const { registerBlockType } = wp.blocks;
  const { useBlockProps } = wp.blockEditor || wp.editor;
  registerBlockType('ssm/subscription-account', {
    edit: () => {
      const blockProps = useBlockProps();
      return wp.element.createElement('div', blockProps, 'SSM Subscription Account block (frontend renders account details).');
    },
    save: () => null
  });
})(window.wp);


