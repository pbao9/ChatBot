const baroAiChatApp = {
  template: `
    <div class="baro-panel" :class="{ hidden: !panelVisible }">
      <div class="baro-header">
        <div class="baro-header-left">
          <div class="baro-ai-icon">
            <img :src="logoUrl" alt="AI Logo" class="baro-logo-img">
          </div>
          <div class="baro-header-info">
            <div class="baro-header-title">{{ title }}</div>
            <div class="baro-header-status">
              <span class="baro-status-dot"></span>
              Đang hoạt động
            </div>
          </div>
        </div>
        <button class="baro-minimize-btn" @click="togglePanel" title="Thu gọn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
        </button>
      </div>
      <div class="baro-body" ref="body">
        <template v-if="!infoSubmitted">
          <div class="baro-info-form">
            <p><strong>Trợ lý tư vấn ChatBot AI</strong></p>
            <p>Vui lòng cung cấp một số thông tin sau để chúng tôi có thể hỗ trợ tốt hơn.</p>
            <div class="form-group">
              <label for="userName">Họ và tên <span>*</span></label>
              <input id="userName" type="text" v-model="userName" placeholder="Họ và tên">
            </div>
            <div class="form-group">
              <label for="userPhone">Số điện thoại <span>*</span></label>
              <input id="userPhone" type="tel" v-model="userPhone" placeholder="Số điện thoại">
            </div>
            <button @click="submitInfo">Tiếp tục</button>
            <p v-if="formError" class="error-msg">{{ formError }}</p>
          </div>
        </template>
        <template v-else-if="history.length === 0">
          <div class="baro-welcome">
            <p v-html="'Xin chào ' + userName + '!<br>Mình là trợ lý AI [Thử nghiệm] từ <strong>' + brand + '</strong>.'"></p>
          </div>
          <ul class="baro-suggestions">
            <li @click="quickSend('Tư vấn sản phẩm')">Tư vấn sản phẩm</li>
            <li @click="quickSend('Chăm sóc khách hàng')">Chăm sóc khách hàng</li>
            <li @click="quickSend('Bảo hành sản phẩm')">Bảo hành sản phẩm</li>
            <li @click="quickSend('Hỗ trợ kỹ thuật')">Hỗ trợ kỹ thuật</li>
          </ul>
        </template>
        <template v-else>
          <div v-for="(msg, index) in history" :key="index" class="baro-msg" :class="msg.role">
            <div class="baro-avatar" v-if="msg.role === 'assistant'">
              <div class="baro-avatar-icon">
                <img :src="logoUrl" alt="AI Logo" class="baro-avatar-img">
              </div>
            </div>
            <div class="baro-msg-content">
              <div class="baro-bubble" :class="{ typing: msg.typing }" v-html="msg.html"></div>
              <div v-if="msg.role === 'user' && !msg.typing" class="baro-msg-status">Đã gửi</div>
            </div>
            <div class="baro-avatar" v-if="msg.role === 'user'">
              <div class="baro-avatar-icon">👤</div>
            </div>
          </div>
          <!-- Show suggestions below chat when there are messages -->
          <div class="baro-suggestions-below" v-if="history.length > 0">
            <div class="baro-suggestions-title">Gợi ý câu hỏi:</div>
            <ul class="baro-suggestions">
              <li @click="quickSend('Tư vấn sản phẩm')">Tư vấn sản phẩm</li>
              <li @click="quickSend('Chăm sóc khách hàng')">Chăm sóc khách hàng</li>
              <li @click="quickSend('Bảo hành sản phẩm')">Bảo hành sản phẩm</li>
              <li @click="quickSend('Hỗ trợ kỹ thuật')">Hỗ trợ kỹ thuật</li>
            </ul>
          </div>
        </template>
      </div>
      <div class="baro-input" v-if="infoSubmitted">
        <input type="text" v-model="newMessage" :placeholder="placeholder" @keydown.enter="sendMessage()">
        <button @click="sendMessage()" class="baro-send-btn" title="Gửi tin nhắn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22,2 15,22 11,13 2,9 22,2"></polygon>
          </svg>
        </button>
      </div>
      <div class="baro-footer">Powered by Thế Giới Số</div>
    </div>
    <button class="baro-fab" :title="'Chat với ' + brand" @click="togglePanel">
      <span class="baro-fab-pulse"></span>
      🤖
    </button>
  `,
  data() {
    return {
      panelVisible: false,
      title: 'Tư vấn nhanh',
      placeholder: 'Nhập câu hỏi...',
      brand: 'Brand',
      history: [], // { role: 'user' | 'assistant', html: '...', typing: bool }
      newMessage: '',
      apiConfig: window.BARO_AI_CFG || {},
      infoSubmitted: false,
      userName: '',
      userPhone: '',
      formError: '',
      logoUrl: '',
    };
  },
  mounted() {
    const rootEl = this.$el.parentElement;
    if (rootEl) {
      this.title = rootEl.dataset.title || this.title;
      this.placeholder = rootEl.dataset.placeholder || this.placeholder;
      this.brand = rootEl.dataset.brand || this.brand;
    }
    this.logoUrl = this.getLogoUrl();
  },
  methods: {
    getLogoUrl() {
      // Get the plugin URL from the global config or construct it
      const pluginUrl = window.BARO_AI_CFG?.pluginUrl || '/wp-content/plugins/baro-ai-chatbot';
      return `${pluginUrl}/assets/images/logo_bubble.png`;
    },
    togglePanel() {
      this.panelVisible = !this.panelVisible;
    },
    quickSend(message) {
      this.newMessage = message;
      this.sendMessage();
    },
    submitInfo() {
      this.formError = '';
      if (!this.userName.trim() || !this.userPhone.trim()) {
        this.formError = 'Vui lòng nhập đầy đủ họ tên và số điện thoại.';
        return;
      }
      // Basic phone validation
      if (!/^\d{10}$/.test(this.userPhone)) {
        this.formError = 'Số điện thoại không hợp lệ (yêu cầu 10 số). ';
        return;
      }
      this.infoSubmitted = true;
      // Send the info to the backend right away
      this.sendMessage(`Tên: ${this.userName}, SĐT: ${this.userPhone}`);
    },
    async sendMessage(initialMessage = '') {
      // Handle case where no parameter is passed (manual input)
      const text = initialMessage || this.newMessage.trim();
      if (!text) return;

      // Add user message to history for display
      this.history.push({ role: 'user', html: text });
      this.newMessage = '';
      this.scrollToBottom();

      // Add typing indicator
      this.history.push({ role: 'assistant', html: 'Đang gõ…', typing: true });
      this.scrollToBottom();

      try {
        // Build API history from all non-typing messages (excluding the current user message)
        const apiHistory = this.history
          .filter(m => !m.typing && m.role === 'assistant')
          .map(m => ({
            role: 'model',
            parts: [{ text: this.stripHtml(m.html) }]
          }));

        
        const res = await fetch(this.apiConfig.restBase + 'chat', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': this.apiConfig.nonce
          },
          body: JSON.stringify({ message: text, history: apiHistory })
        });

        // Remove typing indicator
        this.history = this.history.filter(m => !m.typing);

        if (!res.ok) {
          let err = 'Hệ thống đang bận, vui lòng thử lại.';
          try { const j = await res.json(); if (j && j.error) err = j.error; } catch {}
          this.history.push({ role: 'assistant', html: err });
          return;
        }

        const data = await res.json();
        const answer = (data.answer || '').trim();

        this.history.push({ role: 'assistant', html: answer || 'Xin lỗi, hiện mình chưa có thông tin trong hệ thống.' });
        this.scrollToBottom();

      } catch (e) {
        // Remove typing indicator and show error
        this.history = this.history.filter(m => !m.typing);
        this.history.push({ role: 'assistant', html: 'Kết nối lỗi. Vui lòng thử lại.' });
      }
    },
    scrollToBottom() {
      this.$nextTick(() => {
        const body = this.$refs.body;
        if (body) {
          body.scrollTop = body.scrollHeight;
        }
      });
    },
    stripHtml(html) {
      const tmp = document.createElement("DIV");
      tmp.innerHTML = html;
      return tmp.textContent || tmp.innerText || "";
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('baro-ai-root');
  if (root) {
    Vue.createApp(baroAiChatApp).mount(root);
  }
});