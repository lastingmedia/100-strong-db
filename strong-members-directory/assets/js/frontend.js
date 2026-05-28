( function () {
	'use strict';

	var editors = document.querySelectorAll( '[data-smd-image-editor="true"]' );

	if ( ! editors.length ) {
		return;
	}

	editors.forEach( function ( form ) {
		var fileInput = form.querySelector( '.smd-image-edit-input' );
		var croppedField = form.querySelector( 'input[name="smd_cropped_image_data"]' );
		var modal = document.querySelector( '[data-smd-cropper-modal]' );

		if ( ! fileInput || ! croppedField || ! modal ) {
			return;
		}

		var stage = modal.querySelector( '[data-smd-cropper-stage]' );
		var image = modal.querySelector( '[data-smd-cropper-image]' );
		var zoomInput = modal.querySelector( '[data-smd-cropper-zoom]' );
		var applyButton = modal.querySelector( '[data-smd-cropper-apply]' );
		var cancelButtons = modal.querySelectorAll( '[data-smd-cropper-cancel]' );
		var state = {
			objectUrl: '',
			naturalWidth: 0,
			naturalHeight: 0,
			baseScale: 1,
			zoom: 1,
			offsetX: 0,
			offsetY: 0,
			dragging: false,
			dragStartX: 0,
			dragStartY: 0,
			startOffsetX: 0,
			startOffsetY: 0
		};

		var clampOffsets = function () {
			var frameWidth = stage.clientWidth;
			var frameHeight = stage.clientHeight;
			var scaledWidth = state.naturalWidth * state.baseScale * state.zoom;
			var scaledHeight = state.naturalHeight * state.baseScale * state.zoom;
			var limitX = Math.max( 0, ( scaledWidth - frameWidth ) / 2 );
			var limitY = Math.max( 0, ( scaledHeight - frameHeight ) / 2 );

			state.offsetX = Math.min( limitX, Math.max( -limitX, state.offsetX ) );
			state.offsetY = Math.min( limitY, Math.max( -limitY, state.offsetY ) );
		};

		var render = function () {
			if ( ! state.naturalWidth || ! state.naturalHeight ) {
				return;
			}

			clampOffsets();

			var scale = state.baseScale * state.zoom;
			var width = state.naturalWidth * scale;
			var height = state.naturalHeight * scale;

			image.style.width = width + 'px';
			image.style.height = height + 'px';
			image.style.left = '50%';
			image.style.top = '50%';
			image.style.transform = 'translate(calc(-50% + ' + state.offsetX + 'px), calc(-50% + ' + state.offsetY + 'px))';
		};

		var closeModal = function () {
			modal.hidden = true;
			document.body.classList.remove( 'smd-cropper-open' );
			state.dragging = false;
			if ( state.objectUrl ) {
				URL.revokeObjectURL( state.objectUrl );
				state.objectUrl = '';
			}
			image.removeAttribute( 'src' );
			fileInput.value = '';
			croppedField.value = '';
			zoomInput.value = '1';
		};

		var openModal = function ( file ) {
			if ( ! file.type || 0 !== file.type.indexOf( 'image/' ) ) {
				window.alert( smdFrontend.invalidImage );
				fileInput.value = '';
				return;
			}

			if ( state.objectUrl ) {
				URL.revokeObjectURL( state.objectUrl );
			}

			state.objectUrl = URL.createObjectURL( file );
			image.onload = function () {
				state.naturalWidth = image.naturalWidth;
				state.naturalHeight = image.naturalHeight;
				state.baseScale = Math.max(
					stage.clientWidth / state.naturalWidth,
					stage.clientHeight / state.naturalHeight
				);
				state.zoom = 1;
				state.offsetX = 0;
				state.offsetY = 0;
				zoomInput.value = '1';
				render();
			};
			image.src = state.objectUrl;
			modal.hidden = false;
			document.body.classList.add( 'smd-cropper-open' );
		};

		var applyCrop = function () {
			if ( ! state.naturalWidth || ! state.naturalHeight ) {
				return;
			}

			var outputWidth = 1200;
			var outputHeight = 1500;
			var frameWidth = stage.clientWidth;
			var frameHeight = stage.clientHeight;
			var scale = state.baseScale * state.zoom;
			var scaledWidth = state.naturalWidth * scale;
			var scaledHeight = state.naturalHeight * scale;
			var drawX = ( outputWidth - ( scaledWidth * outputWidth / frameWidth ) ) / 2 + ( state.offsetX * outputWidth / frameWidth );
			var drawY = ( outputHeight - ( scaledHeight * outputHeight / frameHeight ) ) / 2 + ( state.offsetY * outputHeight / frameHeight );
			var drawWidth = scaledWidth * outputWidth / frameWidth;
			var drawHeight = scaledHeight * outputHeight / frameHeight;
			var canvas = document.createElement( 'canvas' );
			var context = canvas.getContext( '2d' );

			canvas.width = outputWidth;
			canvas.height = outputHeight;
			context.fillStyle = '#ffffff';
			context.fillRect( 0, 0, outputWidth, outputHeight );
			context.drawImage( image, drawX, drawY, drawWidth, drawHeight );

			croppedField.value = canvas.toDataURL( 'image/jpeg', 0.92 );
			modal.hidden = true;
			document.body.classList.remove( 'smd-cropper-open' );
			form.submit();
		};

		var startDrag = function ( clientX, clientY ) {
			state.dragging = true;
			state.dragStartX = clientX;
			state.dragStartY = clientY;
			state.startOffsetX = state.offsetX;
			state.startOffsetY = state.offsetY;
			stage.classList.add( 'is-dragging' );
		};

		var continueDrag = function ( clientX, clientY ) {
			if ( ! state.dragging ) {
				return;
			}

			state.offsetX = state.startOffsetX + ( clientX - state.dragStartX );
			state.offsetY = state.startOffsetY + ( clientY - state.dragStartY );
			render();
		};

		var endDrag = function () {
			state.dragging = false;
			stage.classList.remove( 'is-dragging' );
		};

		fileInput.addEventListener( 'change', function () {
			if ( fileInput.files && fileInput.files[0] ) {
				openModal( fileInput.files[0] );
			}
		} );

		zoomInput.addEventListener( 'input', function () {
			state.zoom = parseFloat( zoomInput.value ) || 1;
			render();
		} );

		applyButton.addEventListener( 'click', applyCrop );

		cancelButtons.forEach( function ( button ) {
			button.addEventListener( 'click', closeModal );
		} );

		stage.addEventListener( 'mousedown', function ( event ) {
			event.preventDefault();
			startDrag( event.clientX, event.clientY );
		} );

		window.addEventListener( 'mousemove', function ( event ) {
			continueDrag( event.clientX, event.clientY );
		} );

		window.addEventListener( 'mouseup', endDrag );

		stage.addEventListener( 'touchstart', function ( event ) {
			if ( ! event.touches[0] ) {
				return;
			}
			startDrag( event.touches[0].clientX, event.touches[0].clientY );
		}, { passive: true } );

		window.addEventListener( 'touchmove', function ( event ) {
			if ( ! state.dragging || ! event.touches[0] ) {
				return;
			}
			continueDrag( event.touches[0].clientX, event.touches[0].clientY );
		}, { passive: true } );

		window.addEventListener( 'touchend', endDrag );

		window.addEventListener( 'resize', function () {
			if ( ! modal.hidden ) {
				state.baseScale = Math.max(
					stage.clientWidth / state.naturalWidth,
					stage.clientHeight / state.naturalHeight
				);
				render();
			}
		} );
	} );
}() );
