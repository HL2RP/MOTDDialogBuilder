<?php

require('php/motd_dialog_base.php');

const MAX_PAGE_ITEMS = 8; // Max. items per page, not including navigation ones (split from custom)
const PREV_ITEM_INDEX = -1;
const NEXT_ITEM_INDEX = -2;

enum DIALOG_TYPE: int
{
	case DIALOG_MSG = 0;
	case DIALOG_MENU = 1;
	case DIALOG_TEXT = 2;
	case DIALOG_ENTRY = 3;
	case DIALOG_ASKCONNECT = 4;
}

global $gIP, $initialError;

$initialData = json_encode(NULL); // For initial JS rendering
$fromRCON = empty($_POST); // Whether obtaining initial data from RCON, or POST (for sample dialog forms in the latter)

if ($fromRCON) {
	try {
		$data = sendDialogCommand(EDialogCommand::Query);

		if (is_null(json_decode($data, NULL, 3))) { // NOTE: Depth must be 3+ to detect items (which are really at second level)
			$initialError = $data;
		} else {
			$initialData = $data;
		}
	} catch (Throwable $t) {
		error_log($t);
		$initialError = $t->getMessage();
	}
} else {
	$type = DIALOG_TYPE::tryFrom((int)@$_POST['type']);

	if ($type == DIALOG_TYPE::DIALOG_MENU) {
		// Parse menu items
		$items = [];
		$itemPrefix = 'item_';
		$_POST['items'] = &$items;

		foreach ($_POST as $key => $val) {
			if (count($items) >= MAX_PAGE_ITEMS) {
				break;
			} else if (str_starts_with($key, $itemPrefix)) {
				unset($_POST[$key]); // Remove unneeded element
				$index = substr($key, strlen($itemPrefix));

				if (sscanf($index, '%i', $index) > 0) { // Ensure index part starts with a number
					$items[$index] = $val;
				}
			}
		}
	}

	$initialData = json_encode($_POST, 0, 2); // Encode data, lastly. NOTE: Max. depth matches the expected level in this call (for items).
}

$gIP = is_null($gIP) ? 'undefined' : json_encode($gIP); // Get quoted string, for safe command sending

// Classes
$btnClass = 'btn btn-dark fs-5 px-4 py-2';

?>

<!DOCTYPE html>
<html class="h-100">

<head>
	<title></title> <!-- To be set by JS -->
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="css/motd_dialog_builder.css" />
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" crossorigin="anonymous" />
</head>

<body class="h-100 text-bg-dark">
	<main class="container-fluid h-100 px-4">
		<section class="row py-1">
			<div class="col">
				<h1 id="title" class="text-center"></h1>
			</div>
		</section>

		<section class="row h-75 gy-2 justify-content-center">
			<div id="leftPanel" class="col-auto col-xl"></div>

			<div class="col-lg-8 col-xl-5 d-flex text-center">
				<div class="row row-cols-1 flex-column flex-fill gy-3 mb-2">
					<div class="col d-flex flex-fill">
						<div id="centralPanel" class="row row-cols-xl-1 flex-fill m-0 pt-2 pb-4 gy-3 bg-secondary rounded">
							<!-- Template for menu items -->
							<div class="col-auto item-col d-flex justify-content-center">
								<button type="button" class="<?= $btnClass ?>"></button>
							</div>

							<!-- For non-menu dialogs -->
							<div id="centralMsgCol" class="col-auto">
								<h5 class="msg"></h5>
							</div>

							<!-- For entrybox -->
							<div id="textAreaCol" class="col-auto">
								<textarea class="form-control" rows="3"></textarea>
							</div>

							<!-- For entrybox -->
							<div id="submitCol" class="col-auto">
								<button type="submit" class="<?= $btnClass ?>"></button>
							</div>
						</div>
					</div>

					<div id="navPanel" class="col">
						<div class="row m-0 pt-2 pb-4 gx-3 gy-3 justify-content-center bg-secondary rounded">
							<!-- Template for common navigation buttons -->
							<div class="col-auto nav-col">
								<button type="button" class="<?= $btnClass ?>">
									<i class="bi"></i>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div id="menuMsgPanel" class="col-lg order-first order-lg-last w-100 mb-2">
				<div class="h-100 text-center px-3 bg-secondary rounded">
					<i class="bi bi-info-circle-fill fs-4 text-primary-emphasis"></i>
					<hr class="my-2" />
					<h5 class="text-start msg"></h5>
				</div>
			</div>
		</section>

		<!-- Error toast -->
		<div class="toast fs-5 mb-4 fixed-bottom start-50 translate-middle-x text-bg-danger" role="alert">
			<div class="d-flex">
				<div class="toast-body"></div>
				<button type="button" class="btn-close ms-auto me-2 mt-2" data-bs-dismiss="toast"></button>
			</div>
		</div>
	</main>

	<script src="https://code.jquery.com/jquery-4.0.0.min.js" crossorigin="anonymous"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>

	<script>
		const AJAX_ERROR_DEF_MSG = 'Connection error'

		let $itemColTemplate = $('.item-col').detach(),
			$navColTemplate = $('.nav-col').detach()

		function showError(error) {
			error = error.trim()

			if (error.length < 1) {
				error = AJAX_ERROR_DEF_MSG
			}

			$('.toast-body').text(error)
			bootstrap.Toast.getOrCreateInstance($('.toast')).show()
		}

		function processFields(data, decode) {
			if (decode) {
				for (let key in data) {
					try {
						data[key] = atob(data[key])

						// Convert resulting UTF-8 string to UTF-16 (native encoding), for correct display
						let utf8Arr = Uint8Array.from(data[key], c => c.charCodeAt(0))
						data[key] = (new TextDecoder()).decode(utf8Arr)
					} catch {
						// Accept value anyway
					}
				}
			}

			// Trim values, removing empty ones (safe op)
			for (let key in data) {
				data[key] = data[key].trim()

				if (data[key].length < 1) {
					delete data[key]
				}
			}
		}

		// Adds a navigation item column (from template), and returns the inner button
		function addNavButton(iconClass) {
			let $navCol = $navColTemplate.clone(),
				$button = $navCol.children('button')
			$button.children('i').addClass(iconClass)
			$navCol.appendTo('#navPanel .row')
			return $button
		}

		function sendCommand(args, name = '<?= EDialogCommand::Custom->value ?>') {
			$('button').attr('disabled', true)

			$.post('php/motd_dialog_handler.php', {
				ip: <?= $gIP ?>,
				port: <?= $gPort ?>,
				userid: <?= $gUserId ?>,
				secret: <?= $gSecret ?>,
				command: name,
				args: args
			}).done(data => {
				let lines = data.split('\n')

				// Search for the first JSON object among returned lines, to use at update (ideally a new dialog's data),
				// since RCON responses include console text printed during command handling (e.g. via Msg calls)
				for (line of lines) {
					try {
						data = JSON.parse(line)
						return update(data, true)
					} catch {
						// Nothing, keep searching
					}
				}

				showError(data) // Show potential error contained within response
			}).fail(jqXHR => {
				showError(jqXHR.responseText)
			}).always(() => $('button').removeAttr('disabled'))
		}

		function update(data, decode) {
			$('#title, #leftPanel, #menuMsgPanel, #centralMsgCol, #textAreaCol, #submitCol, #navPanel').hide()
			$('.item-col, .nav-col').remove()
			$('textarea').val('')

			if (!$.isEmptyObject(data)) {
				let items = data.items
				delete data.items // Detach for safe basic processing (string-based)
				processFields(data, decode)

				$('title, #title').text(data.title).show()

				let ctrlPnlMenuClass = 'align-content-start justify-content-center',
					ctrlPnlEntryClass = 'flex-column justify-content-center',
					msgDefaultClass = 'text-start'

				$('#centralPanel').removeClass(`${ctrlPnlMenuClass} ${ctrlPnlEntryClass}`)

				if (data.type == <?= DIALOG_TYPE::DIALOG_MENU->value ?>) {
					$('#centralPanel').addClass(ctrlPnlMenuClass)

					if (data.msg != null) {
						$('#menuMsgPanel .msg').text(data.msg)
						$('#leftPanel, #menuMsgPanel').show()
					}

					if (items != null) {
						Object.keys(items).splice(<?= MAX_PAGE_ITEMS ?>).forEach(i => delete items[i]) // Keep max. items as much
						processFields(items, decode)

						for (let key in items) {
							let $itemCol = $itemColTemplate.clone()
							$itemCol.children('button').attr('data-item-index', key).text(items[key])
							$itemCol.appendTo('#centralPanel')
						}

						// HACK: If items count is odd, add an invisible one to align the last usable one to the left column.
						// This approach is mainly needed since we're using col-auto for the items.
						if (Object.keys(items).length % 2 == 1) {
							let $itemCol = $itemColTemplate.clone()
							$itemCol.addClass('invisible').appendTo('#centralPanel')
						}
					}
				} else {
					let $centralMsg = $('#centralMsgCol > .msg')

					if (data.type == <?= DIALOG_TYPE::DIALOG_ENTRY->value ?>) {
						$centralMsg.removeClass(msgDefaultClass)
						$('#centralPanel').addClass(ctrlPnlEntryClass)
						$('#submitCol').children('button').text(data.submitStr)
						$('#submitCol, #textAreaCol').show()
					} else {
						$centralMsg.addClass(msgDefaultClass)
					}

					$centralMsg.text(data.msg)
					$('#centralMsgCol').show()
				}

				if (data.previous != null) {
					addNavButton('bi-arrow-left-square').attr('data-item-index', <?= PREV_ITEM_INDEX ?>).append(data.previous)
				}

				if (data.next != null) {
					addNavButton('bi-arrow-right-square').attr('data-item-index', <?= NEXT_ITEM_INDEX ?>).prepend(data.next)
				}

				if (data.back != null) {
					let $button = addNavButton('bi-box-arrow-up-left')
					$button.append(data.back).on('click', () => sendCommand(undefined, '<?= EDialogCommand::Rewind->value ?>'))

					// Ensure button gets its own line, to cleanly separate it from Next/Back and keep user from accidental presses
					if ($('.nav-col').length % 2 == 0) {
						$button.parent().addClass('col-12')
					}
				}

				// Add command handler for menu items (including specific navigation ones)
				$('button[data-item-index]').on('click', function() {
					sendCommand($(this).attr('data-item-index'))
				})

				if ($('.nav-col').length > 0) {
					$('#navPanel').show()
				}
			}
		}

		<?php if (isset($initialError)): ?>
			showError(<?= json_encode($initialError) ?>)
		<?php endif ?>

		// Add DIALOG_ENTRY handler
		$('#submitCol > button').on('click', () => sendCommand($('textarea').val()))

		update(<?= $initialData ?>, <?= $fromRCON ?>)
	</script>
</body>

</html>
