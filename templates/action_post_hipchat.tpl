<b>Room:</b>
<div style="margin-left:10px;margin-bottom:10px;">
	<input type="text" name="{$namePrefix}[room]" size="24" style="width:100%;" value="{if !empty($params.room)}{$params.room}{else}{$default_room}{/if}" required="required">
</div>

<b>From:</b>
<div style="margin-left:10px;margin-bottom:10px;">
	<input type="text" name="{$namePrefix}[from]" size="15" maxlength="15" style="width:100%;" value="{$params.from}" required="required">
</div>

<b>Message:</b>
<label><input type="radio" name="{$namePrefix}[is_html]" value="0" {if empty($params.is_html)}checked="checked"{/if}> Text</label>
<label><input type="radio" name="{$namePrefix}[is_html]" value="1" {if $params.is_html}checked="checked"{/if}> HTML</label>
<div style="margin-left:10px;margin-bottom:10px;">
	<textarea name="{$namePrefix}[content]" rows="3" cols="45" required="required" style="width:100%;" class="placeholders">{$params.content}</textarea>
</div>

<b>Color:</b>
<div style="margin-left:10px;margin-bottom:10px;">
	{$colors = ['','yellow','red','green','purple','gray','random']}
	<select name="{$namePrefix}[color]">
		{foreach from=$colors item=color}
		<option value="{$color}" {if $color==$params.color}selected="selected"{/if}>{$color}</option>
		{/foreach}
	</select>
</div>

<script type="text/javascript">
$action = $('fieldset#{$namePrefix}');
$action.find('textarea').elastic();
</script>