<?php
/** @var \OCP\IL10N $l */
?>
<div id="app-navigation">
	<div class="notes-nav-new">
		<button id="notes-new-notebook" type="button"><span class="icon-add"></span> <?php p($l->t('New notebook')); ?></button>
	</div>
	<ul id="notes-nav-list">
		<li class="notes-nav-all active" data-all="1"><a href="#"><span class="icon-files-dark"></span> <?php p($l->t('All notes')); ?></a></li>
	</ul>
	<div class="notes-nav-section" id="notes-nb-header" title="<?php p($l->t('Drop a notebook here to move it to the top level')); ?>"><?php p($l->t('Notebooks')); ?></div>
	<ul id="notes-notebooks"></ul>
	<div class="notes-nav-section"><?php p($l->t('Tags')); ?></div>
	<ul id="notes-tags"></ul>
</div>

<div id="app-content">
	<div id="notes-list-pane">
		<div id="notes-list-toolbar">
			<input type="text" id="notes-search" placeholder="<?php p($l->t('Search notes')); ?>" />
			<div class="notes-toolbar-row">
				<select id="notes-template" title="<?php p($l->t('Template')); ?>">
					<option value=""><?php p($l->t('Blank')); ?></option>
				</select>
				<button id="notes-new-note" type="button" class="primary"><span class="icon-add"></span> <?php p($l->t('New note')); ?></button>
			</div>
		</div>
		<div id="notes-list-context"></div>
		<div id="notes-selection-bar" style="display:none;"></div>
		<ul id="notes-list"></ul>
	</div>

	<div id="notes-editor-pane">
		<div id="notes-editor-empty" class="notes-empty"><?php p($l->t('Select or create a note')); ?></div>
		<div id="notes-editor-wrap" style="display:none;">
			<div id="notes-editor-head">
				<input type="text" id="notes-title" placeholder="<?php p($l->t('Title')); ?>" />
				<button id="notes-save" type="button" class="primary"><?php p($l->t('Save')); ?></button>
				<button id="notes-delete" type="button" title="<?php p($l->t('Delete note')); ?>"><span class="icon-delete"></span></button>
				<span id="notes-status"></span>
			</div>
			<div id="notes-tags-edit">
				<span class="notes-tags-label"><?php p($l->t('Tags:')); ?></span>
				<span id="notes-tag-chips"></span>
				<input type="text" id="notes-tags-input" list="notes-tags-datalist" placeholder="<?php p($l->t('Add tag…')); ?>" />
				<datalist id="notes-tags-datalist"></datalist>
				<label class="notes-footer-toggle"><input type="checkbox" id="notes-show-footer" /> <?php p($l->t('Show metadata footer')); ?></label>
			</div>
			<div id="notes-math-bar" class="notes-math-bar">
				<span class="notes-math-label"><?php p($l->t('Math:')); ?></span>
				<button type="button" data-mb="$|$" title="<?php p($l->t('Inline math: $…$')); ?>">$x$</button>
				<button type="button" data-mb="$$|$$" data-block="1" title="<?php p($l->t('Display (block) math on its own lines: $$…$$')); ?>">$$x$$</button>
				<button type="button" data-mb="\ce{|}" title="<?php p($l->t('Chemistry (mhchem): \\ce{…}')); ?>">\ce{ }</button>
				<button type="button" data-mb="\pu{|}" title="<?php p($l->t('Physical units (mhchem): \\pu{…}')); ?>">\pu{ }</button>
				<span class="notes-math-hint"><?php p($l->t('block = own line · use a_{b} for nested subscripts')); ?></span>
			</div>
			<textarea id="notes-editor"></textarea>
			<pre id="notes-footer-view" style="display:none;"></pre>
		</div>
	</div>
</div>
