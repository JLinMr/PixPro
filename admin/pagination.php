<?php
function renderPagination($current_page, $total_pages) {
    $total = max(1, (int)$total_pages);
    if ($total <= 1) {
        return '';
    }

    $current = min(max(1, (int)$current_page), $total);
    $first = $current <= 1;
    $last = $current >= $total;

    $btn = static function (string $class, int $page, string $label, string $body, bool $off): string {
        return sprintf(
            '<button type="button" class="%s glass-btn%s" data-page="%d" aria-label="%s"%s>%s</button>',
            $class,
            $off ? ' is-disabled' : '',
            $page,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            $off ? ' disabled' : '',
            $body
        );
    };

    $input = sprintf(
        '<input type="number" class="pagination-jumper-input" min="1" max="%1$d" value="%2$d" aria-label="跳转到页码">',
        $total,
        $current
    );

    $dot = '<span class="pagination-jumper-dot">·</span>';
    $jumper = $btn('pagination-edge', 1, '第一页', '1', $first)
        . $dot . $input . $dot
        . $btn('pagination-edge', $total, '最后一页', (string)$total, $last);

    $icon = static fn(string $dir) =>
        '<svg class="icon pagination-icon" aria-hidden="true"><use xlink:href="#icon-' . $dir . '-arrow"></use></svg>';

    return $btn('pagination-prev', max(1, $current - 1), '上一页', $icon('Left'), $first)
        . '<div class="pagination-jumper">' . $jumper . '</div>'
        . $btn('pagination-next', min($total, $current + 1), '下一页', $icon('Right'), $last);
}
