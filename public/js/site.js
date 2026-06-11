const header = document.getElementById('header');
const burger = document.getElementById('burger');
const mobileMenu = document.getElementById('mobileMenu');

const onScroll = () => {
  const scrolled = window.scrollY > 4;
  header?.classList.toggle('is-scrolled', scrolled);
};
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

const toggleMenu = (open) => {
  if (!mobileMenu || !burger) return;
  const willOpen = open ?? !mobileMenu.classList.contains('is-open');
  mobileMenu.classList.toggle('is-open', willOpen);
  burger.classList.toggle('is-open', willOpen);
  burger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  document.body.style.overflow = willOpen ? 'hidden' : '';
};

burger?.addEventListener('click', () => toggleMenu());
mobileMenu?.querySelectorAll('a').forEach((a) => {
  a.addEventListener('click', () => toggleMenu(false));
});

const reveals = document.querySelectorAll('.reveal');
const io = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.12, rootMargin: '0px 0px -8% 0px' }
);
reveals.forEach((el) => io.observe(el));

// ===== Подробные окна направлений =====
const dirModal = document.getElementById('dirModal');
if (dirModal) {
  const details = dirModal.querySelectorAll('.dir-detail');
  let lastFocused = null;

  const resetSlides = (el) => {
    const slides = el.querySelectorAll('.dir-detail__slide');
    slides.forEach((s, idx) => s.classList.toggle('is-active', idx === 0));
  };

  const openDir = (slug) => {
    let found = false;
    details.forEach((el) => {
      const match = el.dataset.dir === slug;
      el.hidden = !match;
      if (match) {
        found = true;
        resetSlides(el);
      }
    });
    if (!found) return;
    lastFocused = document.activeElement;
    dirModal.classList.add('is-open');
    dirModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    dirModal.scrollTop = 0;
    dirModal.querySelector('.dir-modal__close')?.focus();
  };

  const closeDir = () => {
    dirModal.classList.remove('is-open');
    dirModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused) lastFocused.focus();
  };

  document.querySelectorAll('[data-dir]').forEach((btn) => {
    if (btn.closest('.dir-modal')) return;
    btn.addEventListener('click', () => openDir(btn.dataset.dir));
  });

  dirModal.querySelectorAll('[data-close]').forEach((el) => {
    el.addEventListener('click', closeDir);
  });

  dirModal.querySelectorAll('.dir-detail__nav').forEach((btn) => {
    btn.addEventListener('click', () => {
      const hero = btn.closest('.dir-detail__hero');
      if (!hero) return;
      const slides = [...hero.querySelectorAll('.dir-detail__slide')];
      if (slides.length < 2) return;
      let i = slides.findIndex((s) => s.classList.contains('is-active'));
      if (i < 0) i = 0;
      slides[i].classList.remove('is-active');
      i = btn.dataset.nav === 'next' ? (i + 1) % slides.length : (i - 1 + slides.length) % slides.length;
      slides[i].classList.add('is-active');
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && dirModal.classList.contains('is-open')) closeDir();
  });
}

// ===== Модальное окно расписания =====
const schedModal = document.getElementById('schedModal');
const gridSchedule = document.getElementById('gridSchedule');

if (schedModal && gridSchedule && window.__schedConfig) {
  const cfg = window.__schedConfig;
  const typeEl = document.getElementById('schedModalType');
  const datetimeEl = document.getElementById('schedModalDatetime');
  const titleEl = document.getElementById('schedModalTitle');
  const topicEl = document.getElementById('schedModalTopic');
  const trainerEl = document.getElementById('schedModalTrainer');
  const durationEl = document.getElementById('schedModalDuration');
  const seatsEl = document.getElementById('schedModalSeats');
  const descEl = document.getElementById('schedModalDesc');
  const actionEl = document.getElementById('schedModalAction');

  const closeSchedModal = () => {
    schedModal.classList.remove('is-open');
    schedModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  };

  const openSchedModal = (slot) => {
    const title = slot.direction || slot.topic || slot.title;
    const typeLabel = cfg.typeLabels[slot.type] || slot.type_label || slot.type;

    typeEl.textContent = typeLabel;
    typeEl.className = `badge badge--${slot.type}`;
    datetimeEl.textContent = slot.date_time;
    titleEl.textContent = title;

    if (slot.direction && slot.topic) {
      topicEl.textContent = slot.topic;
      topicEl.hidden = false;
    } else {
      topicEl.textContent = '';
      topicEl.hidden = true;
    }

    trainerEl.textContent = slot.trainer;
    durationEl.textContent = `${slot.time_range} · ${slot.duration_minutes} мин`;

    if (slot.status === 'cancelled') {
      seatsEl.textContent = `Отменено${slot.reason ? `: ${slot.reason}` : ''}`;
    } else if (slot.status === 'full') {
      seatsEl.textContent = `Мест нет · ${slot.total} из ${slot.total} занято`;
    } else {
      seatsEl.textContent = `Свободно ${slot.free} из ${slot.total}`;
    }

    if (slot.description) {
      descEl.textContent = slot.description;
      descEl.hidden = false;
    } else {
      descEl.textContent = '';
      descEl.hidden = true;
    }

    actionEl.innerHTML = '';

    if (slot.is_reschedule_source) {
      actionEl.innerHTML = '<span class="btn btn--ghost" style="pointer-events:none;width:100%">Текущая запись</span>';
    } else if (slot.can_reschedule_here && cfg.rescheduleUrl) {
      actionEl.innerHTML = `
        <form action="${cfg.rescheduleUrl}" method="post">
          <input type="hidden" name="_token" value="${cfg.csrf}" />
          <input type="hidden" name="class_session_id" value="${slot.id}" />
          <button type="submit" class="btn btn--solid">Перенести сюда</button>
        </form>`;
    } else if (slot.user_booked) {
      actionEl.innerHTML = '<span class="btn btn--ghost" style="pointer-events:none;width:100%">Вы записаны</span>';
    } else if (slot.status === 'open' && slot.bookable && cfg.isClient) {
      actionEl.innerHTML = `
        <form action="${cfg.bookUrl}" method="post">
          <input type="hidden" name="_token" value="${cfg.csrf}" />
          <input type="hidden" name="class_session_id" value="${slot.id}" />
          <button type="submit" class="btn btn--solid">Записаться</button>
        </form>`;
    } else if (slot.status === 'open' && slot.bookable) {
      actionEl.innerHTML = `<a href="${cfg.loginUrl}" class="btn btn--solid">Войти и записаться</a>`;
    } else if (slot.status === 'full') {
      actionEl.innerHTML = '<button type="button" class="btn btn--ghost" disabled style="width:100%">Мест нет</button>';
    } else {
      actionEl.innerHTML = '<button type="button" class="btn btn--ghost" disabled style="width:100%">Отменено</button>';
    }

    schedModal.classList.add('is-open');
    schedModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  gridSchedule.querySelectorAll('.gridsched__card').forEach((card) => {
    card.addEventListener('click', () => {
      try {
        openSchedModal(JSON.parse(card.dataset.session));
      } catch (e) {
        console.error('Schedule card data parse error', e);
      }
    });
  });

  schedModal.querySelectorAll('[data-sched-close]').forEach((el) => {
    el.addEventListener('click', closeSchedModal);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && schedModal.classList.contains('is-open')) {
      closeSchedModal();
    }
  });
}

// ===== Аккордеон FAQ =====
const faqList = document.getElementById('faqList');
if (faqList) {
  faqList.querySelectorAll('.faq__q').forEach((q) => {
    q.addEventListener('click', () => {
      const item = q.closest('.faq__item');
      const answer = item.querySelector('.faq__a');
      const isOpen = item.classList.toggle('is-open');
      answer.style.maxHeight = isOpen ? answer.scrollHeight + 'px' : null;
    });
  });
}

const togglePasswordVisibility = (btn) => {
  const field = btn.closest('.password-field');
  const input = field?.querySelector('input');
  if (!input) return;

  const showIcon = btn.querySelector('.password-field__icon--show');
  const hideIcon = btn.querySelector('.password-field__icon--hide');
  const visible = input.type === 'text';

  input.type = visible ? 'password' : 'text';
  btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
  btn.setAttribute('aria-label', visible ? 'Показать пароль' : 'Скрыть пароль');
  showIcon?.toggleAttribute('hidden', !visible);
  hideIcon?.toggleAttribute('hidden', visible);
};

// ===== Автоскрытие уведомлений на странице входа =====
document.querySelectorAll('[data-auto-dismiss]').forEach((alert) => {
  const delay = Number.parseInt(alert.dataset.autoDismiss, 10);
  if (!Number.isFinite(delay) || delay <= 0) return;

  window.setTimeout(() => {
    alert.classList.add('is-dismissing');
    window.setTimeout(() => alert.remove(), 300);
  }, delay);
});

// ===== Вкладки входа / регистрации =====
const authTabs = document.querySelectorAll('.auth__tab');
if (authTabs.length) {
  const forms = document.querySelectorAll('.auth__form');
  const authPanels = document.querySelectorAll('[data-auth-panel]');
  const authTabsWrap = document.querySelector('[data-auth-tabs]');
  const switchAuth = (name) => {
    authTabs.forEach((t) => {
      const on = t.dataset.tab === name;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    forms.forEach((f) => f.classList.toggle('is-hidden', f.dataset.form !== name));
    authPanels.forEach((panel) => {
      panel.classList.toggle('is-hidden', panel.dataset.authPanel !== name);
    });
    if (authTabsWrap) {
      authTabsWrap.classList.toggle(
        'is-hidden',
        ['verify-email', 'reset-request', 'reset-verify'].includes(name),
      );
    }
  };
  authTabs.forEach((t) => t.addEventListener('click', () => switchAuth(t.dataset.tab)));
  document.querySelectorAll('[data-goto]').forEach((b) => b.addEventListener('click', () => switchAuth(b.dataset.goto)));

  const initialAuthTab = document.querySelector('.auth__form:not(.is-hidden)')?.dataset.form;
  if (['verify-email', 'reset-request', 'reset-verify'].includes(initialAuthTab)) {
    switchAuth(initialAuthTab);
  }

  const patronymicToggle = document.getElementById('patronymic-toggle');
  const patronymicField = document.getElementById('patronymic-field');
  if (patronymicToggle && patronymicField) {
    const syncPatronymic = () => {
      patronymicField.classList.toggle('is-hidden', !patronymicToggle.checked);
      if (!patronymicToggle.checked) {
        const input = patronymicField.querySelector('input');
        if (input) input.value = '';
      }
    };
    patronymicToggle.addEventListener('change', syncPatronymic);
    syncPatronymic();
  }

}

document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
  btn.addEventListener('click', () => togglePasswordVisibility(btn));
});

// ===== Модалка «функция в разработке» =====
const soonModal = document.getElementById('soonModal');
if (soonModal) {
  const textEl = document.getElementById('soonModalText');
  const defaultText = textEl ? textEl.textContent : '';
  let lastFocusedSoon = null;

  const openSoon = (msg) => {
    if (textEl) textEl.textContent = msg && msg.trim() ? msg : defaultText;
    lastFocusedSoon = document.activeElement;
    soonModal.classList.add('is-open');
    soonModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    soonModal.querySelector('.soon-modal__close')?.focus();
  };

  const closeSoon = () => {
    soonModal.classList.remove('is-open');
    soonModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocusedSoon) lastFocusedSoon.focus();
  };

  document.querySelectorAll('[data-soon]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      openSoon(btn.getAttribute('data-soon'));
    });
  });

  soonModal.querySelectorAll('[data-soon-close]').forEach((el) => {
    el.addEventListener('click', closeSoon);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && soonModal.classList.contains('is-open')) closeSoon();
  });
}

// ===== Разделы личного кабинета =====
const lkNav = document.getElementById('lkNav');
if (lkNav) {
  const links = lkNav.querySelectorAll('.lk__navlink[data-sec]');
  const panels = document.querySelectorAll('.lk__panel');

  const switchLkSection = (section) => {
    if (!section) return;
    const link = lkNav.querySelector(`.lk__navlink[data-sec="${section}"]`);
    if (!link) return;
    links.forEach((l) => l.classList.toggle('is-active', l === link));
    panels.forEach((p) => p.classList.toggle('is-hidden', p.dataset.panel !== section));
  };

  links.forEach((link) => {
    link.addEventListener('click', () => switchLkSection(link.dataset.sec));
  });

  switchLkSection(lkNav.dataset.initialSection);
}

// ===== Редактирование профиля в ЛК =====
const profileView = document.getElementById('profileView');
const profileEdit = document.getElementById('profileEdit');
const profileEditForm = document.getElementById('profileEditForm');

if (profileView && profileEdit && profileEditForm) {
  const profileEditBtn = document.getElementById('profileEditBtn');
  const profileCancelBtn = document.getElementById('profileCancelBtn');
  const profileSaveBtn = document.getElementById('profileSaveBtn');
  const profileEmailInput = document.getElementById('profile-email');
  const profilePanelTitle = document.getElementById('profilePanelTitle');
  const profileEmailVerify = document.getElementById('profileEmailVerify');
  const profileSendCodeBtn = document.getElementById('profileSendCodeBtn');
  const profileVerifyEmailBtn = document.getElementById('profileVerifyEmailBtn');
  const profileEmailCode = document.getElementById('profile-email-code');
  const profileCodeRow = document.getElementById('profileCodeRow');
  const profileEmailSendForm = document.getElementById('profileEmailSendForm');
  const profileEmailVerifyForm = document.getElementById('profileEmailVerifyForm');
  const profilePatronymicToggle = document.getElementById('profile-patronymic-toggle');
  const profilePatronymicField = document.getElementById('profile-patronymic-field');
  const profileFieldNames = [
    'first_name',
    'last_name',
    'patronymic',
    'birth_day',
    'birth_month',
    'birth_year',
    'phone',
    'email',
  ];

  const normalizeEmail = (value) => (value || '').trim().toLowerCase();

  const getOriginalEmail = () => normalizeEmail(profileEditForm.dataset.originalEmail);
  const getVerifiedEmail = () => normalizeEmail(profileEditForm.dataset.verifiedEmail);
  const getFormEmail = () => normalizeEmail(profileEmailInput?.value);

  const emailChangeRequiresVerification = () => {
    const formEmail = getFormEmail();
    if (!formEmail) return false;
    return formEmail !== getOriginalEmail();
  };

  const isEmailVerifiedForForm = () => {
    const formEmail = getFormEmail();
    if (!emailChangeRequiresVerification()) return true;
    return getVerifiedEmail() === formEmail;
  };

  const syncProfileEmailState = () => {
    const needsVerify = emailChangeRequiresVerification();
    const verified = isEmailVerifiedForForm();

    if (profileEmailVerify) {
      profileEmailVerify.classList.toggle('is-hidden', !needsVerify || verified);
    }
    if (profileSaveBtn) {
      profileSaveBtn.disabled = needsVerify && !verified;
    }

    if (profileCodeRow) {
      const showCodeRow = needsVerify && !verified && (
        profileEditForm.dataset.codeSent === '1'
        || profileEditForm.dataset.pendingEmail === getFormEmail()
      );
      profileCodeRow.classList.toggle('is-hidden', !showCodeRow);
    }

    if (needsVerify && profileEmailCode && profileEditForm.dataset.codeSent === '1') {
      profileEmailCode.focus();
    }
  };

  const copyProfileFieldsToForm = (targetForm, extra = {}) => {
    if (!targetForm) return;
    targetForm.querySelectorAll('[data-profile-field]').forEach((el) => el.remove());

    profileFieldNames.forEach((name) => {
      const source = profileEditForm.elements[name];
      if (!source) return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = source.value;
      input.dataset.profileField = '1';
      targetForm.appendChild(input);
    });

    Object.entries(extra).forEach(([name, value]) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      input.dataset.profileField = '1';
      targetForm.appendChild(input);
    });
  };

  const openProfileEdit = () => {
    profileView.classList.add('is-hidden');
    profileEdit.classList.remove('is-hidden');
    if (profilePanelTitle) profilePanelTitle.textContent = 'Редактирование профиля';
    syncProfileEmailState();
  };

  const closeProfileEdit = () => {
    profileEdit.classList.add('is-hidden');
    profileView.classList.remove('is-hidden');
    if (profilePanelTitle) profilePanelTitle.textContent = 'Профиль';
  };

  profileEditBtn?.addEventListener('click', openProfileEdit);
  profileCancelBtn?.addEventListener('click', closeProfileEdit);

  profileEmailInput?.addEventListener('input', () => {
    const formEmail = getFormEmail();
    if (formEmail !== getVerifiedEmail()) {
      profileEditForm.dataset.verifiedEmail = '';
    }
    syncProfileEmailState();
  });

  profileSendCodeBtn?.addEventListener('click', () => {
    if (!profileEmailInput?.value.trim()) {
      profileEmailInput?.focus();
      return;
    }
    copyProfileFieldsToForm(profileEmailSendForm);
    profileEmailSendForm?.submit();
  });

  profileVerifyEmailBtn?.addEventListener('click', () => {
    const code = profileEmailCode?.value.trim() || '';
    if (!code) {
      profileEmailCode?.focus();
      return;
    }
    copyProfileFieldsToForm(profileEmailVerifyForm, { code });
    profileEmailVerifyForm?.submit();
  });

  if (profilePatronymicToggle && profilePatronymicField) {
    const syncPatronymic = () => {
      profilePatronymicField.classList.toggle('is-hidden', !profilePatronymicToggle.checked);
      if (!profilePatronymicToggle.checked) {
        const input = profilePatronymicField.querySelector('input');
        if (input) input.value = '';
      }
    };
    profilePatronymicToggle.addEventListener('change', syncPatronymic);
    syncPatronymic();
  }

  syncProfileEmailState();
}
