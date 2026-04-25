(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // -------------------------------------------------------------------------
    // Compose form: confirm modal + submit guard
    // -------------------------------------------------------------------------
    var sendBtn = document.getElementById('bkc-send');
    if (sendBtn) {
      sendBtn.addEventListener('click', function (e) {
        e.preventDefault();

        var title = (document.getElementById('bkc-title') || {}).value || '';
        var body  = (document.getElementById('bkc-body')  || {}).value || '';

        var confirmed = window.confirm(
          '푸쉬 알림을 발송하시겠습니까?\n\n제목: ' + title + '\n내용: ' + body.substring(0, 60) + (body.length > 60 ? '…' : '')
        );

        if (!confirmed) {
          return;
        }

        // Disable button to prevent double-submit.
        sendBtn.disabled = true;
        sendBtn.textContent = '발송 중…';

        // Submit the parent form.
        var form = document.getElementById('bkc-compose-form');
        if (form) {
          form.submit();
        }
      });
    }

    // -------------------------------------------------------------------------
    // Compose form: live preview pane
    // -------------------------------------------------------------------------
    var titleInput = document.getElementById('bkc-title');
    var bodyInput  = document.getElementById('bkc-body');
    var previewPane  = document.getElementById('bkc-preview-pane');
    var previewTitle = document.getElementById('bkc-preview-title');
    var previewBody  = document.getElementById('bkc-preview-body');

    function updatePreview() {
      var t = titleInput ? titleInput.value.trim() : '';
      var b = bodyInput  ? bodyInput.value.trim()  : '';
      if (t || b) {
        if (previewPane)  previewPane.style.display  = 'block';
        if (previewTitle) previewTitle.textContent   = t;
        if (previewBody)  previewBody.textContent    = b;
      } else {
        if (previewPane) previewPane.style.display = 'none';
      }
    }

    if (titleInput) titleInput.addEventListener('input', updatePreview);
    if (bodyInput)  bodyInput.addEventListener('input', updatePreview);

    // -------------------------------------------------------------------------
    // Compose form: mutual-exclusion logic for group checkboxes
    // 'all' is mutually exclusive with specific groups.
    // -------------------------------------------------------------------------
    var allCheckbox       = document.getElementById('bkc-group-all');
    var specificCheckboxes = [
      document.getElementById('bkc-group-youth'),
      document.getElementById('bkc-group-newfamily')
    ].filter(Boolean);

    if (allCheckbox) {
      allCheckbox.addEventListener('change', function () {
        if (allCheckbox.checked) {
          // Uncheck and disable specific groups when 'all' is selected.
          specificCheckboxes.forEach(function (cb) {
            cb.checked  = false;
            cb.disabled = true;
          });
        } else {
          // Re-enable specific group checkboxes.
          specificCheckboxes.forEach(function (cb) {
            cb.disabled = false;
          });
        }
      });

      // If 'all' starts checked, disable specific boxes on load.
      if (allCheckbox.checked) {
        specificCheckboxes.forEach(function (cb) {
          cb.disabled = true;
        });
      }
    }

    specificCheckboxes.forEach(function (cb) {
      cb.addEventListener('change', function () {
        var anySpecificChecked = specificCheckboxes.some(function (c) { return c.checked; });
        if (anySpecificChecked && allCheckbox) {
          allCheckbox.checked = false;
        }
      });
    });

    // -------------------------------------------------------------------------
    // Campaign list: Cancel button via REST API
    // -------------------------------------------------------------------------
    var cancelBtns = document.querySelectorAll('.bkc-cancel-btn');
    cancelBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var uuid = btn.getAttribute('data-uuid');
        if (!uuid) return;

        var confirmed = window.confirm('이 캠페인을 취소하시겠습니까?');
        if (!confirmed) return;

        btn.disabled = true;
        btn.textContent = '취소 중…';

        var nonce   = (window.bkcAdmin && window.bkcAdmin.nonce)   || '';
        var restUrl = (window.bkcAdmin && window.bkcAdmin.restUrl) || '/wp-json/bkc/v1';

        fetch(restUrl + '/campaigns/' + encodeURIComponent(uuid) + '/cancel', {
          method:  'POST',
          headers: {
            'X-WP-Nonce':   nonce,
            'Content-Type': 'application/json'
          }
        })
        .then(function (res) {
          if (res.ok) {
            // Update row status in the DOM.
            var row = btn.closest('tr');
            if (row) {
              var statusCell = row.querySelector('.bkc-status');
              if (statusCell) {
                statusCell.textContent = 'cancelled';
                statusCell.className   = 'bkc-status bkc-status--cancelled';
              }
              btn.remove();
            }
          } else {
            return res.json().then(function (data) {
              window.alert('취소 실패: ' + (data.message || res.status));
              btn.disabled    = false;
              btn.textContent = '취소';
            });
          }
        })
        .catch(function (err) {
          window.alert('오류: ' + err.message);
          btn.disabled    = false;
          btn.textContent = '취소';
        });
      });
    });

  }); // DOMContentLoaded

}());
