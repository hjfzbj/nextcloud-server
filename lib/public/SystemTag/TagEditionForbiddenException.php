<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCP\SystemTag;

/**
 * Exception when a user don't have the rigth to create a tag
 *
 * @since 31.0.0
 */
class TagEditionForbiddenException extends \RuntimeException {
}
