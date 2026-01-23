<?php

/**
 * Generator that yields rows across Cassandra pages
 */
function paged_rows($page) {
    while (true) {
        foreach ($page as $row) {
            yield $row;
        }
        if ($page->isLastPage()) break;
        $page = $page->nextPage();
    }
}
