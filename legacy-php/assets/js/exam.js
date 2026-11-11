/**
 * Language Hub — Exam Engine JavaScript (Corrected & Standardized)
 * Handles: timer, question navigation, answer selection, anti-cheat, submit
 */
'use strict';

const ExamEngine = (() => {
  let timer        = null;
  let secondsLeft  = 0;
  let attemptId    = null;
  let tabSwitches  = 0;
  let submitted    = false;

  // ── Init ──────────────────────────────────────────────────────────────────
  function init() {
    // Config read from EXAM_CONFIG defined in exam.php
    const config = window.EXAM_CONFIG || {};
    secondsLeft  = parseInt(config.remaining || 0, 10);
    attemptId    = parseInt(config.attemptId || 0, 10);
    ExamEngine.answers = config.savedAnswers || {};
    ExamEngine.current = 0;

    startTimer();
    attachAntiCheat();
    setupAutosave(config.autosaveInterval || 30);
    
    // Show the first question, hide others
    goTo(0);
    updateProgress();
  }

  // ── Timer ─────────────────────────────────────────────────────────────────
  function startTimer() {
    const el = document.getElementById('timerDisplay');
    if (!el) return;
    
    if (secondsLeft > 31000000) {
      el.textContent = 'Unlimited';
      return; // Skip timer interval
    }
    
    el.textContent = formatTime(secondsLeft);
    
    timer = setInterval(() => {
      secondsLeft--;
      el.textContent = formatTime(secondsLeft);
      
      if (secondsLeft <= 60) {
        el.style.color = 'var(--c-error, #ef4444)';
      }
      if (secondsLeft <= 0) {
        clearInterval(timer);
        autoSubmit();
      }
    }, 1000);
  }

  function formatTime(s) {
    if (s <= 0) return "00:00";
    const m = Math.floor(s / 60);
    const sec = s % 60;
    return `${pad(m)}:${pad(sec)}`;
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  // ── Question Navigation ───────────────────────────────────────────────────
  function goTo(index) {
    if (typeof EXAM_QUESTIONS === 'undefined' || !EXAM_QUESTIONS.length) return;
    if (index < 0 || index >= EXAM_QUESTIONS.length) return;

    ExamEngine.current = index;

    // Hide all question cards
    document.querySelectorAll('.question-card').forEach(card => {
      card.style.display = 'none';
    });

    // Show current question card
    const currentQ = EXAM_QUESTIONS[index];
    const activeCard = document.getElementById('q' + currentQ.id);
    if (activeCard) {
      activeCard.style.display = 'block';
    }

    // Update navigation buttons (prev / next) state
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    if (btnPrev) btnPrev.disabled = (index === 0);
    if (btnNext) {
      if (index === EXAM_QUESTIONS.length - 1) {
        btnNext.innerHTML = 'Finish Exam <span class="material-icons-round">check_circle</span>';
        btnNext.setAttribute('onclick', 'ExamEngine.confirmSubmit()');
      } else {
        btnNext.innerHTML = 'Next Question <span class="material-icons-round">arrow_forward</span>';
        btnNext.setAttribute('onclick', `ExamEngine.goTo(ExamEngine.current + 1)`);
      }
    }

    // Highlight active palette button
    document.querySelectorAll('[id^="palette_"]').forEach(btn => {
      btn.style.borderColor = 'var(--c-border)';
      btn.style.boxShadow = 'none';
    });
    const activePalette = document.getElementById('palette_' + currentQ.id);
    if (activePalette) {
      activePalette.style.borderColor = 'var(--c-primary)';
      activePalette.style.boxShadow = '0 0 0 2px rgba(91, 33, 182, 0.2)';
    }
  }

  function selectOption(questionId, optionId) {
    if (submitted) return;
    
    // Save locally
    ExamEngine.answers[questionId] = optionId;

    // Update options CSS (remove selected from all, add to chosen)
    const card = document.getElementById('q' + questionId);
    if (card) {
      card.querySelectorAll('.option-label').forEach(lbl => {
        lbl.classList.remove('selected');
        lbl.style.borderColor = 'var(--c-border)';
        lbl.style.background = '#fff';
        const span = lbl.querySelector('span[style*="background"]');
        if (span) {
          span.style.background = 'var(--c-surface)';
          span.style.color = 'var(--c-ink)';
        }
      });

      // Find the selected input radio
      const radio = card.querySelector(`input[name="q_${questionId}"][value="${optionId}"]`);
      if (radio) {
        radio.checked = true;
        const lbl = radio.closest('.option-label');
        if (lbl) {
          lbl.classList.add('selected');
          lbl.style.borderColor = 'var(--c-primary)';
          lbl.style.background = 'rgba(91, 33, 182,.07)';
          const span = lbl.querySelector('span:first-of-type');
          if (span) {
            span.style.background = 'var(--c-primary)';
            span.style.color = '#fff';
          }
        }
      }
    }

    // Update palette button representation
    const paletteBtn = document.getElementById('palette_' + questionId);
    if (paletteBtn) {
      paletteBtn.style.background = 'var(--c-primary)';
      paletteBtn.style.color = '#fff';
    }

    updateProgress();
  }

  // ── Progress ──────────────────────────────────────────────────────────────
  function updateProgress() {
    const answered = Object.keys(ExamEngine.answers).length;
    const countEl = document.getElementById('answeredCount');
    if (countEl) {
      countEl.textContent = answered;
    }
  }

  // ── Anti-cheat ────────────────────────────────────────────────────────────
  function attachAntiCheat() {
    document.addEventListener('visibilitychange', () => {
      if (document.hidden && !submitted) {
        tabSwitches++;
        if (tabSwitches >= 3) {
          alert('Warning! Switching tabs is not allowed. This session is being tracked.');
        }
      }
    });

    document.addEventListener('contextmenu', e => e.preventDefault());
    document.addEventListener('copy', e => e.preventDefault());
    document.addEventListener('keydown', e => {
      if ((e.ctrlKey && ['c','v','a','u'].includes(e.key.toLowerCase())) ||
          e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) {
        e.preventDefault();
      }
    });
  }

  // ── Autosave ─────────────────────────────────────────────────────────────
  function setupAutosave(intervalSec) {
    setInterval(() => {
      if (!submitted && attemptId && Object.keys(ExamEngine.answers).length) {
        fetch(EXAM_CONFIG.submitUrl + '?action=autosave', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
          body: JSON.stringify({ attempt_id: attemptId, answers: ExamEngine.answers }),
        }).catch(() => {});
      }
    }, intervalSec * 1000);
  }

  // ── Submit ────────────────────────────────────────────────────────────────
  function confirmSubmit() {
    if (submitted) return;
    const total = EXAM_QUESTIONS.length;
    const answered = Object.keys(ExamEngine.answers).length;
    const unanswered = total - answered;

    const modal = document.getElementById('submitModal');
    const modalAns = document.getElementById('modalAnswered');
    const modalUnans = document.getElementById('modalUnanswered');

    if (modal) {
      if (modalAns) modalAns.textContent = answered;
      if (modalUnans) modalUnans.textContent = unanswered;
      modal.style.display = 'flex';
    } else {
      if (confirm(`You have answered ${answered} question(s). Do you want to submit the exam?`)) {
        doSubmit();
      }
    }
  }

  function autoSubmit() {
    if (submitted) return;
    alert('Time is up! The exam is being submitted automatically.');
    doSubmit();
  }

  async function doSubmit() {
    if (submitted) return;
    submitted = true;
    clearInterval(timer);

    const modal = document.getElementById('submitModal');
    if (modal) modal.style.display = 'none';

    try {
      const response = await fetch(EXAM_CONFIG.submitUrl + '?action=submit', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({
          attempt_id: attemptId,
          answers: ExamEngine.answers,
          time_taken: Math.max(0, (EXAM_CONFIG.remaining - secondsLeft))
        })
      });
      const data = await response.json();
      if (data.success && data.result_url) {
        window.location.href = data.result_url;
      } else {
        alert(data.message || 'There was a problem submitting the exam.');
        submitted = false;
      }
    } catch (e) {
      alert('Network problem. Please try again.');
      submitted = false;
    }
  }

  return {
    current: 0,
    answers: {},
    init,
    goTo,
    selectOption,
    confirmSubmit,
    doSubmit
  };
})();

window.ExamEngine = ExamEngine;
