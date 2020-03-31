<?php

print _('String with formatting %s') . _('String without formatting') . ngettext('Singular_1 %s', 'Plural_1', 2) . ngettext('Singular_2', 'Plural_2 %d', 3) . ngettext('Singular_3', 'Plural_3', 2);