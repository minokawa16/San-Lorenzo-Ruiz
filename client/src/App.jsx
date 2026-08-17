import { useEffect, useRef, useState } from 'react';

export default function App() {
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const threadEndRef = useRef(null);

  useEffect(() => {
    threadEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, loading]);

  async function sendMessage(event) {
    event.preventDefault();
    const message = input.trim();
    if (!message || loading) return;

    const history = messages.map(({ role, content }) => ({ role, content }));
    setMessages((current) => [...current, { role: 'user', content: message }]);
    setInput('');
    setError('');
    setLoading(true);

    try {
      const response = await fetch('/api/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message, history }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || 'Unable to send your message.');

      setMessages((current) => [...current, { role: 'assistant', content: data.reply }]);
    } catch (requestError) {
      setError(requestError.message || 'Something went wrong. Please try again.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="app-shell">
      <section className="chat-card" aria-label="Gemini chatbot">
        <header className="chat-header">
          <span className="brand-mark" aria-hidden="true">✦</span>
          <div>
            <h1>Gemini Chat</h1>
            <p>Securely connected through your Express backend</p>
          </div>
        </header>

        <div className="message-list" aria-live="polite">
          {messages.length === 0 && (
            <div className="empty-state">
              <span aria-hidden="true">✦</span>
              <h2>How can I help?</h2>
              <p>Your API key stays on the server.</p>
            </div>
          )}
          {messages.map((message, index) => (
            <article className={`message ${message.role}`} key={`${message.role}-${index}`}>
              <span className="message-role">{message.role === 'user' ? 'You' : 'Gemini'}</span>
              <p>{message.content}</p>
            </article>
          ))}
          {loading && (
            <div className="message assistant loading-message" role="status">
              <span className="message-role">Gemini</span>
              <span className="typing-dots"><i /><i /><i /></span>
            </div>
          )}
          <div ref={threadEndRef} />
        </div>

        <footer className="composer-area">
          {error && <p className="error-message" role="alert">{error}</p>}
          <form className="composer" onSubmit={sendMessage}>
            <label className="sr-only" htmlFor="chat-input">Message Gemini</label>
            <textarea
              id="chat-input"
              value={input}
              onChange={(event) => setInput(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                  event.preventDefault();
                  event.currentTarget.form?.requestSubmit();
                }
              }}
              placeholder="Ask anything…"
              rows="1"
              maxLength="4000"
              disabled={loading}
            />
            <button type="submit" disabled={loading || !input.trim()}>
              {loading ? 'Sending…' : 'Send'}
            </button>
          </form>
          <small>Gemini can make mistakes. Verify important information.</small>
        </footer>
      </section>
    </main>
  );
}

