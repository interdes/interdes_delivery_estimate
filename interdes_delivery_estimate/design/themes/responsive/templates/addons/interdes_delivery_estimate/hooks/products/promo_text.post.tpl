{if $product.product_id}
    {assign var="interdes_de_rows" value=$product.product_id|fn_interdes_delivery_estimate_get_estimate}

    {if $interdes_de_rows}
        {assign var="interdes_de_city" value=$product.product_id|fn_interdes_delivery_estimate_get_city}

        <div class="ut2-pb__note interdes-delivery-estimate">
            <div class="interdes-delivery-estimate__city">
                Город доставки: {$interdes_de_city}
            </div>
            <ul class="interdes-delivery-estimate__list">
                {foreach from=$interdes_de_rows item="interdes_de_row"}
                    <li class="interdes-delivery-estimate__item">
                        <span class="interdes-delivery-estimate__name">{$interdes_de_row.name}</span>
                        <span class="interdes-delivery-estimate__price">{include file="common/price.tpl" value=$interdes_de_row.price}</span>
                        {if $interdes_de_row.delivery_time}
                            <span class="interdes-delivery-estimate__time">{$interdes_de_row.delivery_time}</span>
                        {/if}
                    </li>
                {/foreach}
            </ul>
        </div>
    {/if}
{/if}
