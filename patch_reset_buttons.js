/* Replace '+1 test' with a true 'Reset' button and neutralize old handlers */
(function(){
  function wireAsReset(oldBtn){
    if (!oldBtn || oldBtn.dataset.resetWired === '1') return;

    // Hapus handler lama dengan clone+replace (semua event hilang)
    const btn = oldBtn.cloneNode(true);
    btn.textContent = 'Reset';
    btn.classList.add('btn-danger');
    btn.removeAttribute('onclick'); // buang inline onclick bila ada
    oldBtn.replaceWith(btn);

    btn.dataset.resetWired = '1';

    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();

      const tr = btn.closest('tr');
      let operatorId =
        tr?.dataset?.operatorId ||
        tr?.getAttribute('data-operator-id') ||
        tr?.getAttribute('data-id') ||
        tr?.getAttribute('data-operator') || '';

      if (!operatorId) { alert('Cannot detect operator_id from row'); return; }

      // Prompt sederhana; Cancel = benar2 tidak melakukan apa2
      const project = prompt('Project baru (wajib):', '');
      if (project === null || !project.trim()) return;

      const step = prompt('Step baru (opsional):', '');
      if (step === null) return;

      const part = prompt('Part baru (opsional):', '');
      if (part === null) return;

      fetch('server/operator_reset.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
          operator_id: parseInt(operatorId,10),
          project: project.trim(),
          step: step || undefined,
          part: part || undefined,
          status: 'reset',
          remarks: 'UI reset'
        })
      })
      .then(r => r.json())
      .then(j => {
        if (j && j.status === 'ok') {
          btn.classList.add('pulse');
          setTimeout(()=>btn.classList.remove('pulse'), 800);
        } else {
          alert('Reset failed: ' + (j && j.message ? j.message : 'Unknown error'));
        }
      })
      .catch(e => alert('Reset error: ' + e));
    });
  }

  function scan(){
    document.querySelectorAll('button, a.btn').forEach((b)=>{
      const label = (b.textContent || '').trim().toLowerCase();
      if (label.includes('+1 test') || b.dataset.role === 'plus-test') {
        wireAsReset(b);
      }
    });
  }

  const mo = new MutationObserver(scan);
  mo.observe(document.documentElement, {childList:true, subtree:true});
  document.addEventListener('DOMContentLoaded', scan);
  window.addEventListener('load', scan);
  setInterval(scan, 1500);
})();
