<script>
(function ($) {
    var $showAll = $('.interdes-de-show-all-shipping-methods');
    var $shippingIdsGroup = $showAll.closest('.control-group').next('.control-group');

    function toggleShippingIdsGroup() {
        $shippingIdsGroup.toggle(!$showAll.is(':checked'));
    }

    $showAll.on('change', toggleShippingIdsGroup);
    toggleShippingIdsGroup();
})(Tygh.$);
</script>
