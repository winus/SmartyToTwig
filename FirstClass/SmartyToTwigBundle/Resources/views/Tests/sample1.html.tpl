{extends file="file:SmartyToTwig:Tests:base.html.tpl"}
{block name="body"}
{* Comment style one *}
{*}Comment style 2{*}

{$simpleVar}

{foreach from=$data item="entry"}
{foreachelse}
{/foreach}

{foreach from=$data item=entry}
    {break}
{foreachelse}
{/foreach}
{strip}
    This block
    Should be stripped of crlf.
{/strip}

{/block}