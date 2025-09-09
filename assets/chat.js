(() => {
  const cfg = window.BARO_AI_CFG || {};
  const h = (tag, props={}, ...children) => {
    const el = document.createElement(tag);
    Object.entries(props).forEach(([k,v]) => {
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else el.setAttribute(k, v);
    });
    children.forEach(c => typeof c === 'string' ? el.appendChild(document.createTextNode(c)) : c && el.appendChild(c));
    return el;
  };

  const root = document.getElementById('baro-ai-root');
  if (!root) return;
  const title = root.dataset.title || 'Tư vấn nhanh';
  const placeholder = root.dataset.placeholder || 'Nhập câu hỏi...';
  const brand = root.dataset.brand || 'Brand';

  let history = [];

  const bubble = (role, html) => {
    const wrap = h('div', {class: `baro-msg ${role}`});
    const content = h('div', {class: 'baro-bubble', html});
    wrap.appendChild(content);
    return wrap;
  };

  const panel = h('div', {class: 'baro-panel hidden'},
    h('div', {class: 'baro-header'}, title),
    h('div', {class: 'baro-body', id: 'baro-body'}),
    h('div', {class: 'baro-input'},
      h('input', {type:'text', id:'baro-inp', placeholder}),
      h('button', {id:'baro-send'}, 'Gửi')
    )
  );

  const fab = h('button', {class:'baro-fab', title:'Chat với ' + brand},
    h('span', {class:'baro-fab-pulse'}), '🤖'
  );

  root.appendChild(panel);
  root.appendChild(fab);

  const body = panel.querySelector('#baro-body');
  const inp  = panel.querySelector('#baro-inp');
  const send = panel.querySelector('#baro-send');

  const initialView = () => {
    body.innerHTML = ''; // Clear the body
    const welcome = h('div', {class: 'baro-welcome'},
      h('p', {html: `Xin chào!<br>Mình là trợ lý AI [Thử nghiệm] từ <strong>${brand}</strong>.`}),
    );
    const suggestions = h('ul', {class: 'baro-suggestions'},
      h('li', {html: 'Tư vấn sản phẩm'}),
      h('li', {html: 'Chăm sóc khách hàng'}),
      h('li', {html: 'Bảo hành sản phẩm'}),
      h('li', {html: 'Hỗ trợ kỹ thuật'}),
    );
    body.appendChild(welcome);
    body.appendChild(suggestions);

    suggestions.querySelectorAll('li').forEach(li => {
      li.addEventListener('click', () => {
        inp.value = li.innerText;
        sendMsg();
      });
    });
  };

  const toggle = () => {
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden') && history.length === 0) {
        initialView();
    }
  }
  fab.addEventListener('click', toggle);

  const sendMsg = async () => {
    if (body.querySelector('.baro-welcome')) {
        body.innerHTML = '';
    }

    const text = inp.value.trim();
    if (!text) return;
    inp.value = '';
    body.appendChild(bubble('user', text));
    body.scrollTop = body.scrollHeight;
    history.push({role: 'user', parts: [{text}]});

    const loader = bubble('assistant', 'Đang gõ…');
    loader.querySelector('.baro-bubble').classList.add('typing');
    body.appendChild(loader);
    body.scrollTop = body.scrollHeight;

    try {
      const res = await fetch(cfg.restBase + 'chat', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce
        },
        body: JSON.stringify({ message: text, history })
      });

      loader.remove();

      if (!res.ok) {
        let err = 'Hệ thống đang bận, vui lòng thử lại.';
        try { const j = await res.json(); if (j && j.error) err = j.error } catch {}
        body.appendChild(bubble('assistant', err));
        return;
      }
      const data = await res.json();
      const answer = (data.answer || '').trim();
      body.appendChild(bubble('assistant', answer || 'Xin lỗi, hiện mình chưa có thông tin trong hệ thống. Bạn cho mình số/Zalo để hỗ trợ chi tiết ạ?'));
      history.push({role: 'model', parts: [{text: answer}]});
      body.scrollTop = body.scrollHeight;
    } catch(e) {
      loader.remove();
      body.appendChild(bubble('assistant', 'Kết nối lỗi. Vui lòng thử lại.'));
    }
  };

  send.addEventListener('click', sendMsg);
  inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') sendMsg(); });

  initialView(); // Show on first load
})();
