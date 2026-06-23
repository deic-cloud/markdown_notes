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
	<div class="notes-nav-section" id="notes-nb-header" title="<?php p($l->t('Drop a notebook here to move it to the top level')); ?>">
		<span class="notes-nb-header-label"><?php p($l->t('Notebooks')); ?></span>
		<span id="notes-nb-delall" class="notes-nb-delall" role="button" style="display:none">
			<svg viewBox="0 0 24 24"><path d="M9,3V4H4V6H5V19A2,2 0 0,0 7,21H17A2,2 0 0,0 19,19V6H20V4H15V3H9M7,6H17V19H7V6M9,8V17H11V8H9M13,8V17H15V8H13Z"/></svg>
		</span>
	</div>
	<ul id="notes-notebooks"></ul>
	<div class="notes-nav-section"><?php p($l->t('Tags')); ?></div>
	<ul id="notes-tags"></ul>
	<div id="notes-sync-info">
		<div class="notes-syncbox">
			<span class="notes-syncbox-content">
				<span class="notes-syncbox-title"><?php p($l->t('Joplin sync URL')); ?></span>
				<span class="notes-syncbox-desc" id="notes-sync-url"></span>
			</span>
			<button type="button" id="notes-sync-copy" class="notes-syncbox-icon" title="<?php p($l->t('Copy to clipboard')); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19,21H8V7H19M19,5H8A2,2 0 0,0 6,7V21A2,2 0 0,0 8,23H19A2,2 0 0,0 21,21V7A2,2 0 0,0 19,5M16,1H4A2,2 0 0,0 2,3V17H4V3H16V1Z"></path></svg></button>
		</div>
	</div>
</div>

<div id="app-content">
	<div id="notes-list-pane">
		<div id="notes-list-toolbar">
			<input type="text" id="notes-search" placeholder="<?php p($l->t('Search notes')); ?>" />
			<div class="notes-toolbar-row">
				<button id="notes-new-note" type="button" class="primary"><span class="notes-plus">+</span> <?php p($l->t('New note')); ?></button>
					<button id="notes-new-todo" type="button"><span class="notes-plus">+</span> <?php p($l->t('New to-do')); ?></button>
					<select id="notes-template" title="<?php p($l->t('Template')); ?>">
						<option value=""></option>
					</select>
			</div>
			<div class="notes-toolbar-row notes-sort-row">
				<label class="notes-sort-label" for="notes-sort"><?php p($l->t('Sort:')); ?></label>
				<select id="notes-sort" title="<?php p($l->t('Sort field')); ?>">
					<option value="updated"><?php p($l->t('Updated')); ?></option>
					<option value="created"><?php p($l->t('Created')); ?></option>
					<option value="title"><?php p($l->t('Title')); ?></option>
					<option value="due"><?php p($l->t('Due date')); ?></option>
				</select>
				<button id="notes-sort-dir" type="button" title="<?php p($l->t('Reverse sort order')); ?>">↓</button>
			</div>
		</div>
		<div id="notes-list-context"></div>
		<div id="notes-selection-bar" style="display:none;"></div>
		<ul id="notes-list"></ul>
		<div id="notes-meta-table-wrap" style="display:none;"></div>
	</div>

	<div id="notes-editor-pane">
		<div id="notes-editor-empty" class="notes-empty"><?php p($l->t('Select or create a note')); ?></div>
		<div id="notes-editor-wrap" style="display:none;">
			<div id="notes-editor-head">
				<button id="notes-back" type="button" title="<?php p($l->t('Back to list')); ?>">← <?php p($l->t('List')); ?></button>
				<input type="text" id="notes-title" placeholder="<?php p($l->t('Title')); ?>" />
				<button id="notes-save" type="button" class="primary"><?php p($l->t('Save')); ?></button>
				<button id="notes-delete" type="button" title="<?php p($l->t('Delete note')); ?>"><span class="icon-delete"></span></button>
				<span id="notes-status"></span>
			</div>
			<div id="notes-location" class="notes-location"></div>
			<div id="notes-tags-edit">
				<span class="notes-tags-label"><?php p($l->t('Tags:')); ?></span>
				<span id="notes-tag-chips"></span>
				<input type="text" id="notes-tags-input" list="notes-tags-datalist" placeholder="<?php p($l->t('Add tag…')); ?>" />
				<datalist id="notes-tags-datalist"></datalist>
				<label class="notes-footer-toggle"><input type="checkbox" id="notes-show-footer" /> <?php p($l->t('Show metadata footer')); ?></label>
			</div>
			<div id="notes-todo-edit" style="display:none;">
				<label id="notes-due-wrap"><?php p($l->t('Due:')); ?>
					<input type="datetime-local" id="notes-due" />
					<button type="button" id="notes-due-clear" title="<?php p($l->t('Clear due date')); ?>">×</button>
				</label>
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
<div id="notes-busy" class="notes-busy" style="display:none">
	<div class="notes-busy-box">
		<span class="notes-busy-spin"></span>
		<span class="notes-busy-msg"></span>
	</div>
</div>
