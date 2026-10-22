/* Voice search (Web Speech API). Degrades gracefully: unsupported browsers simply don't see the mic button. */
(function () {
  'use strict';
  const btn = document.getElementById('voiceBtn'), input = document.getElementById('searchInput');
  if (!btn || !input) return;
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) { btn.hidden = true; return; }                       // fallback: typing still works
  const form = input.form, idle = input.placeholder;
  let rec = null, finalText = '', active = false;

  function stopUi() { active = false; btn.classList.remove('listening'); input.placeholder = idle; }

  function start() {
    rec = new SR();
    rec.lang = document.documentElement.dataset.speech || 'en-IN';   // ta-IN when the Tamil UI is selected
    rec.interimResults = true; rec.continuous = false; rec.maxAlternatives = 1;
    finalText = '';
    rec.onstart = () => { active = true; btn.classList.add('listening'); input.value = ''; input.placeholder = (window.I18N && I18N.listening) || 'Listening…'; };
    rec.onresult = (e) => {                                          // live transcription into the box
      let txt = '';
      for (let i = 0; i < e.results.length; i++) { txt += e.results[i][0].transcript; if (e.results[i].isFinal) finalText = txt; }
      input.value = txt;
    };
    rec.onerror = (e) => {
      stopUi();
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') window.toast && toast('Microphone permission is blocked. Allow it in the browser address bar.', true);
      else if (e.error === 'no-speech') window.toast && toast('No speech detected. Try again.', true);
      else if (e.error === 'network') window.toast && toast('Voice search needs an internet connection.', true);
    };
    rec.onend = () => {                                              // speech stopped -> search automatically
      const spoken = (finalText || input.value).trim(); stopUi();
      if (spoken) { input.value = spoken; form.requestSubmit ? form.requestSubmit() : form.submit(); }
    };
    try { rec.start(); } catch (e) { stopUi(); }
  }

  btn.addEventListener('click', () => { if (active && rec) rec.stop(); else start(); });
})();
