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
        <div class="baro-header-actions">
          <button class="baro-clear-btn" @click="clearChatHistory" title="Xóa lịch sử chat" v-if="history.length > 0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3,6 5,6 21,6"></polyline>
              <path d="m19,6v14a2,2 0 0,1 -2,2H7a2,2 0 0,1 -2,-2V6m3,0V4a2,2 0 0,1 2,-2h4a2,2 0 0,1 2,2v2"></path>
            </svg>
          </button>
          <button class="baro-minimize-btn" @click="togglePanel" title="Thu gọn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
          </button>
        </div>
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
              <div v-if="!msg.typing && msg.timestamp" class="baro-msg-status">
                {{ formatTime(msg.timestamp) }}
              </div>
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
      <div class="baro-footer">
      <a href="https://tgs.com.vn" target="_blank" class="baro-footer-link">
        <small>Powered by </small>
        <img :src="primaryLogoUrl" alt="Thế Giới Số" class="baro-footer-logo">
      </a>
      </div>
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
      history: [], // { role: 'user' | 'assistant', html: '...', typing: bool, timestamp: number }
      newMessage: '',
      apiConfig: window.BARO_AI_CFG || {},
      infoSubmitted: false,
      userName: '',
      userPhone: '',
      formError: '',
      logoUrl: '',
      primaryLogoUrl: '',
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
    this.primaryLogoUrl = this.getPrimaryLogoUrl();
    this.loadChatHistory();
  },
  methods: {
    getLogoUrl() {
      // Get the plugin URL from the global config or construct it
      const pluginUrl = window.BARO_AI_CFG?.pluginUrl || '/wp-content/plugins/baro-ai-chatbot';
      return `${pluginUrl}/assets/images/logo_bubble.png`;
    },
    getPrimaryLogoUrl() {
      // Get the plugin URL from the global config or construct it
      const pluginUrl = window.BARO_AI_CFG?.pluginUrl || '/wp-content/plugins/baro-ai-chatbot';
      return `${pluginUrl}/assets/images/logo_primary.png`;
    },
    togglePanel() {
      this.panelVisible = !this.panelVisible;
    },
    quickSend(message) {
      this.newMessage = message;
      this.sendMessage();
    },
    async submitInfo() {
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
      
      // Send form data to backend without showing in chat
      try {
        const res = await fetch(this.apiConfig.restBase + 'chat', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': this.apiConfig.nonce
          },
          body: JSON.stringify({ 
            message: `Tên: ${this.userName}, SĐT: ${this.userPhone}`,
            is_form_submission: true
          })
        });

        if (res.ok) {
          this.infoSubmitted = true;
          // Show welcome message without the form data
          this.history.push({ 
            role: 'assistant', 
            html: `Xin chào ${this.userName}!<br>Mình là trợ lý AI từ <strong>${this.brand}</strong>. Bạn có thể hỏi mình bất kỳ câu hỏi nào về sản phẩm và dịch vụ nhé!`,
            timestamp: Date.now()
          });
          this.saveChatHistory();
        } else {
          this.formError = 'Có lỗi xảy ra, vui lòng thử lại.';
        }
      } catch (e) {
        this.formError = 'Có lỗi xảy ra, vui lòng thử lại.';
      }
    },
    async sendMessage(initialMessage = '') {
      // Handle case where no parameter is passed (manual input)
      const text = initialMessage || this.newMessage.trim();
      if (!text) return;

      // Add user message to history for display
      this.history.push({ role: 'user', html: text, timestamp: Date.now() });
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
          this.history.push({ role: 'assistant', html: err, timestamp: Date.now() });
          this.saveChatHistory();
          return;
        }

        const data = await res.json();
        const answer = (data.answer || '').trim();

        this.history.push({ role: 'assistant', html: answer || 'Xin lỗi, hiện mình chưa có thông tin trong hệ thống.', timestamp: Date.now() });
        this.scrollToBottom();
        this.saveChatHistory();

      } catch (e) {
        // Remove typing indicator and show error
        this.history = this.history.filter(m => !m.typing);
        this.history.push({ role: 'assistant', html: 'Kết nối lỗi. Vui lòng thử lại.', timestamp: Date.now() });
        this.saveChatHistory();
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
    },
    // LocalStorage methods
    saveChatHistory() {
      try {
        const chatData = {
          history: this.history.filter(msg => !msg.typing), // Don't save typing indicators
          userName: this.userName,
          userPhone: this.userPhone,
          infoSubmitted: this.infoSubmitted,
          timestamp: Date.now()
        };
        localStorage.setItem('baro_ai_chat_history', JSON.stringify(chatData));
      } catch (e) {
        console.warn('Could not save chat history to localStorage:', e);
      }
    },
    loadChatHistory() {
      try {
        const saved = localStorage.getItem('baro_ai_chat_history');
        if (saved) {
          const chatData = JSON.parse(saved);
          // Only load if data is less than 7 days old
          if (chatData.timestamp && (Date.now() - chatData.timestamp) < 7 * 24 * 60 * 60 * 1000) {
            this.history = chatData.history || [];
            this.userName = chatData.userName || '';
            this.userPhone = chatData.userPhone || '';
            this.infoSubmitted = chatData.infoSubmitted || false;
          }
        }
      } catch (e) {
        console.warn('Could not load chat history from localStorage:', e);
      }
    },
    clearChatHistory() {
      try {
        localStorage.removeItem('baro_ai_chat_history');
        this.history = [];
        this.infoSubmitted = false;
        this.userName = '';
        this.userPhone = '';
      } catch (e) {
        console.warn('Could not clear chat history from localStorage:', e);
      }
    },
    // Time formatting
    formatTime(timestamp) {
      if (!timestamp) return '';
      
      const now = Date.now();
      const diff = now - timestamp;
      
      // Less than 1 minute
      if (diff < 60000) {
        return 'Vừa xong';
      }
      
      // Less than 1 hour
      if (diff < 3600000) {
        const minutes = Math.floor(diff / 60000);
        return `${minutes} phút trước`;
      }
      
      // Less than 24 hours
      if (diff < 86400000) {
        const hours = Math.floor(diff / 3600000);
        return `${hours} giờ trước`;
      }
      
      // More than 24 hours - show date
      const date = new Date(timestamp);
      const today = new Date();
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      
      if (date.toDateString() === today.toDateString()) {
        return `Hôm nay ${date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}`;
      } else if (date.toDateString() === yesterday.toDateString()) {
        return `Hôm qua ${date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}`;
      } else {
        return date.toLocaleDateString('vi-VN', { 
          day: '2-digit', 
          month: '2-digit', 
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });
      }
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('baro-ai-root');
  if (root) {
    Vue.createApp(baroAiChatApp).mount(root);
  }
});