<?php

// HTML-attribute-safe escape shared by every field this filter bar renders.
function adminFilterBarEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Renders one labeled filter input or select from a field config array.
function renderAdminFilterField(array $field): void
{
    $type = (string) ($field['type'] ?? 'text');
    $name = (string) ($field['name'] ?? '');
    $id = (string) ($field['id'] ?? $name);
    $label = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $name)));
    $value = (string) ($field['value'] ?? '');
    ?>
    <label for="<?php echo adminFilterBarEscape($id); ?>">
        <?php echo adminFilterBarEscape($label); ?>
        <?php if ($type === 'select'): ?>
            <select id="<?php echo adminFilterBarEscape($id); ?>" name="<?php echo adminFilterBarEscape($name); ?>">
                <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                    <option value="<?php echo adminFilterBarEscape($optionValue); ?>"<?php echo $value === (string) $optionValue ? ' selected' : ''; ?>>
                        <?php echo adminFilterBarEscape($optionLabel); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input
                type="<?php echo adminFilterBarEscape($type); ?>"
                id="<?php echo adminFilterBarEscape($id); ?>"
                name="<?php echo adminFilterBarEscape($name); ?>"
                value="<?php echo adminFilterBarEscape($value); ?>"
                <?php if (isset($field['placeholder'])): ?>placeholder="<?php echo adminFilterBarEscape($field['placeholder']); ?>"<?php endif; ?>
                <?php if (isset($field['min'])): ?>min="<?php echo adminFilterBarEscape($field['min']); ?>"<?php endif; ?>
                <?php if (isset($field['step'])): ?>step="<?php echo adminFilterBarEscape($field['step']); ?>"<?php endif; ?>
            >
        <?php endif; ?>
    </label>
    <?php
}

// Renders a full search/filter form (inline fields, optional modal, optional search box) from config.
function renderAdminFilterBar(array $config): void
{
    $action = (string) ($config['action'] ?? '');
    $search = $config['search'] ?? null;
    $inlineFields = $config['inline_fields'] ?? [];
    $modalFields = $config['modal_fields'] ?? [];
    $hiddenFields = $config['hidden_fields'] ?? [];
    $submitLabel = (string) ($config['submit_label'] ?? 'Apply');
    $showSubmit = (bool) ($config['show_submit'] ?? true);
    $clearLabel = (string) ($config['clear_label'] ?? 'Clear');
    $clearHref = (string) ($config['clear_href'] ?? $action);
    $modalId = (string) ($config['modal_id'] ?? 'admin-filter-modal');
    $modalTitle = (string) ($config['modal_title'] ?? 'Filter');
    ?>
    <form class="customer-filter-bar admin-filter-bar<?php echo $modalFields ? ' has-filter-modal' : ''; ?>" method="get" action="<?php echo adminFilterBarEscape($action); ?>">
        <?php foreach ($hiddenFields as $name => $value): ?>
            <input type="hidden" name="<?php echo adminFilterBarEscape($name); ?>" value="<?php echo adminFilterBarEscape($value); ?>">
        <?php endforeach; ?>

        <?php if (is_array($search)): ?>
            <label class="admin-filter-search" for="<?php echo adminFilterBarEscape($search['id'] ?? $search['name']); ?>">
                <?php echo adminFilterBarEscape($search['label'] ?? 'Search'); ?>
                <input
                    type="search"
                    id="<?php echo adminFilterBarEscape($search['id'] ?? $search['name']); ?>"
                    name="<?php echo adminFilterBarEscape($search['name']); ?>"
                    value="<?php echo adminFilterBarEscape($search['value'] ?? ''); ?>"
                    placeholder="<?php echo adminFilterBarEscape($search['placeholder'] ?? ''); ?>"
                >
            </label>
        <?php endif; ?>

        <?php foreach ($inlineFields as $field): ?>
            <?php renderAdminFilterField($field); ?>
        <?php endforeach; ?>

        <div class="admin-filter-actions">
            <?php if ($showSubmit): ?>
                <button type="submit"><?php echo adminFilterBarEscape($submitLabel); ?></button>
            <?php endif; ?>
            <?php if ($modalFields): ?>
                <button type="button" class="filter-modal-button" data-filter-open="<?php echo adminFilterBarEscape($modalId); ?>">Filter</button>
            <?php endif; ?>
            <a class="cancel-edit-link" href="<?php echo adminFilterBarEscape($clearHref); ?>"><?php echo adminFilterBarEscape($clearLabel); ?></a>
        </div>

        <?php if ($modalFields): ?>
            <div class="admin-filter-modal-backdrop" id="<?php echo adminFilterBarEscape($modalId); ?>" data-filter-modal hidden>
                <section class="admin-filter-modal" role="dialog" aria-modal="true" aria-labelledby="<?php echo adminFilterBarEscape($modalId); ?>-title">
                    <header class="admin-filter-modal-header">
                        <h2 id="<?php echo adminFilterBarEscape($modalId); ?>-title"><?php echo adminFilterBarEscape($modalTitle); ?></h2>
                        <button type="button" aria-label="Close filter" data-filter-close>&times;</button>
                    </header>

                    <div class="admin-filter-modal-grid">
                        <?php foreach ($modalFields as $field): ?>
                            <?php renderAdminFilterField($field); ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="admin-filter-modal-actions">
                        <button type="submit">Apply Filter</button>
                        <a class="cancel-edit-link" href="<?php echo adminFilterBarEscape($clearHref); ?>">Clear</a>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($modalFields): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('[data-filter-open]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var modal = document.getElementById(button.getAttribute('data-filter-open'));
                        if (modal) {
                            modal.hidden = false;
                        }
                    });
                });

                document.querySelectorAll('[data-filter-modal]').forEach(function (modal) {
                    modal.addEventListener('click', function (event) {
                        if (event.target === modal || event.target.hasAttribute('data-filter-close')) {
                            modal.hidden = true;
                        }
                    });
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        document.querySelectorAll('[data-filter-modal]').forEach(function (modal) {
                            modal.hidden = true;
                        });
                    }
                });
            });
        </script>
    <?php endif; ?>
    <?php
}
