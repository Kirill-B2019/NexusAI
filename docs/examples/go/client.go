// NEXUS AI — Go клиент.
//
// Установка:
//   go mod init nexus-client
//   go get github.com/go-resty/resty/v2
//
// Использование:
//   client := nexus.NewClient("http://31.128.38.96/api", os.Getenv("NEXUS_KEY"))
//   resp, err := client.Chat(nexus.ChatRequest{
//       Message: "Что такое API Gateway?",
//       Expert:  "system_architect",
//   })
//   fmt.Println(resp.Content)
package nexus

import (
	"bufio"
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Client — клиент NEXUS AI API.
type Client struct {
	BaseURL string
	APIKey  string
	HTTP    *http.Client
}

// NewClient создаёт клиента.
func NewClient(baseURL, apiKey string) *Client {
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"),
		APIKey:  apiKey,
		HTTP: &http.Client{
			Timeout: 300 * time.Second,
		},
	}
}

// ─── Типы ──────────────────────────────────────────────────

type ChatRequest struct {
	Message              string   `json:"message"`
	Expert               string   `json:"expert,omitempty"`
	Experts              []string `json:"experts,omitempty"`
	Thinking             bool     `json:"thinking,omitempty"`
	ProjectID            string   `json:"project_id,omitempty"`
	ConversationID       string   `json:"conversation_id,omitempty"`
	Orchestrate          bool     `json:"orchestrate"`
	UseLLMAggregator     bool     `json:"use_llm_aggregator,omitempty"`
	SaveToConversation   bool     `json:"save_to_conversation,omitempty"`
	UseRAG               bool     `json:"use_rag"`
	RAGTopK              int      `json:"rag_top_k,omitempty"`
	RAGMinScore          float64  `json:"rag_min_score,omitempty"`
	DocumentIDs          []string `json:"document_ids,omitempty"`
}

type Source struct {
	Index        int     `json:"index"`
	DocumentID   string  `json:"document_id"`
	DocumentName string  `json:"document_name,omitempty"`
	ChunkIndex   int     `json:"chunk_index"`
	Score        float64 `json:"score"`
	Preview      string  `json:"preview,omitempty"`
}

type ChatResponse struct {
	Mode            string                 `json:"mode"`
	Expert          string                 `json:"expert,omitempty"`
	Content         string                 `json:"content,omitempty"`
	Reasoning       string                 `json:"reasoning,omitempty"`
	Aggregated      string                 `json:"aggregated,omitempty"`
	ExpertsUsed     []string               `json:"experts_used,omitempty"`
	ExpertsSkipped  []map[string]string    `json:"experts_skipped,omitempty"`
	RouteReason     string                 `json:"route_reason,omitempty"`
	RouteMethod     string                 `json:"route_method,omitempty"`
	Aggregator      string                 `json:"aggregator,omitempty"`
	Sources         []Source               `json:"sources,omitempty"`
	ElapsedSeconds  float64                `json:"elapsed_s"`
	RAGUsed         bool                   `json:"rag_used,omitempty"`
	MessageIDs      map[string]string      `json:"message_ids,omitempty"`
}

type Expert struct {
	Key         string `json:"key"`
	Name        string `json:"name"`
	Description string `json:"description,omitempty"`
	Icon        string `json:"icon,omitempty"`
	Color       string `json:"color,omitempty"`
	SortOrder   int    `json:"sort_order,omitempty"`
}

type Document struct {
	ID               string  `json:"id"`
	ProjectID        string  `json:"project_id"`
	Filename         string  `json:"filename"`
	OriginalFilename string  `json:"original_filename"`
	FileSize         int64   `json:"file_size"`
	MimeType         string  `json:"mime_type"`
	Status           string  `json:"status"`
	ChunksCount      int     `json:"chunks_count"`
	Error            string  `json:"error,omitempty"`
	CreatedAt        string  `json:"created_at"`
}

type SSEEvent struct {
	Type string
	Data json.RawMessage
}

// ─── Основные методы ───────────────────────────────────────

func (c *Client) Health() (map[string]any, error) {
	var result map[string]any
	err := c.doJSON("GET", "/health", nil, &result)
	return result, err
}

func (c *Client) ListExperts(enabledOnly bool) ([]Expert, error) {
	path := "/v1/experts"
	if !enabledOnly {
		path = "/v1/experts/all"
	}
	var resp struct {
		Experts []Expert `json:"experts"`
	}
	err := c.doJSON("GET", path, nil, &resp)
	return resp.Experts, err
}

func (c *Client) Chat(req ChatRequest) (*ChatResponse, error) {
	if req.Expert == "" && len(req.Experts) == 0 {
		req.Orchestrate = true
	}
	if req.Expert != "" {
		req.Orchestrate = false
	}

	var resp ChatResponse
	err := c.doJSON("POST", "/v1/chat", req, &resp)
	return &resp, err
}

// StreamChat возвращает канал SSE-событий.
func (c *Client) StreamChat(req ChatRequest) (<-chan SSEEvent, error) {
	body, err := json.Marshal(req)
	if err != nil {
		return nil, err
	}

	httpReq, err := http.NewRequest("POST", c.BaseURL+"/v1/chat/stream", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	httpReq.Header.Set("X-API-Key", c.APIKey)
	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Accept", "text/event-stream")

	resp, err := c.HTTP.Do(httpReq)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != 200 {
		resp.Body.Close()
		return nil, fmt.Errorf("HTTP %d", resp.StatusCode)
	}

	events := make(chan SSEEvent, 100)

	go func() {
		defer close(events)
		defer resp.Body.Close()

		scanner := bufio.NewScanner(resp.Body)
		scanner.Buffer(make([]byte, 1024*1024), 1024*1024)

		var eventType string
		for scanner.Scan() {
			line := scanner.Text()
			if strings.HasPrefix(line, "event: ") {
				eventType = strings.TrimPrefix(line, "event: ")
			} else if strings.HasPrefix(line, "data: ") {
				data := strings.TrimPrefix(line, "data: ")
				events <- SSEEvent{Type: eventType, Data: json.RawMessage(data)}
			}
		}
	}()

	return events, nil
}

// UploadDocument загружает файл в проект.
func (c *Client) UploadDocument(projectID, filePath string) (*Document, error) {
	file, err := os.Open(filePath)
	if err != nil {
		return nil, err
	}
	defer file.Close()

	var buf bytes.Buffer
	writer := multipart.NewWriter(&buf)

	part, err := writer.CreateFormFile("file", filepath.Base(filePath))
	if err != nil {
		return nil, err
	}
	if _, err := io.Copy(part, file); err != nil {
		return nil, err
	}
	writer.Close()

	httpReq, err := http.NewRequest(
		"POST",
		fmt.Sprintf("%s/v1/projects/%s/documents", c.BaseURL, projectID),
		&buf,
	)
	if err != nil {
		return nil, err
	}
	httpReq.Header.Set("X-API-Key", c.APIKey)
	httpReq.Header.Set("Content-Type", writer.FormDataContentType())

	resp, err := c.HTTP.Do(httpReq)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("HTTP %d: %s", resp.StatusCode, string(body))
	}

	var doc Document
	if err := json.NewDecoder(resp.Body).Decode(&doc); err != nil {
		return nil, err
	}
	return &doc, nil
}

// ─── Внутренние хелперы ────────────────────────────────────

func (c *Client) doJSON(method, path string, payload any, out any) error {
	var body io.Reader
	if payload != nil {
		data, err := json.Marshal(payload)
		if err != nil {
			return err
		}
		body = bytes.NewReader(data)
	}

	req, err := http.NewRequest(method, c.BaseURL+path, body)
	if err != nil {
		return err
	}
	req.Header.Set("X-API-Key", c.APIKey)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		respBody, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("HTTP %d: %s", resp.StatusCode, string(respBody))
	}

	if out == nil {
		return nil
	}
	return json.NewDecoder(resp.Body).Decode(out)
}

// ─── Пример использования ──────────────────────────────────

func Example() error {
	client := NewClient("http://31.128.38.96/api", os.Getenv("NEXUS_KEY"))

	// Healthcheck
	health, err := client.Health()
	if err != nil {
		return err
	}
	fmt.Printf("Health: %v\n", health["status"])

	// Эксперты
	experts, err := client.ListExperts(true)
	if err != nil {
		return err
	}
	for _, e := range experts {
		fmt.Printf("  %s %s: %s\n", e.Icon, e.Key, e.Name)
	}

	// Чат
	resp, err := client.Chat(ChatRequest{
		Message: "Что такое API Gateway? Кратко.",
		Expert:  "system_architect",
		UseRAG:  false,
	})
	if err != nil {
		return err
	}
	fmt.Printf("\nЧат: %s\n", resp.Content)

	// Стрим (первые 20 токенов)
	fmt.Println("\nСтрим:")
	events, err := client.StreamChat(ChatRequest{
		Message: "Что такое монолит?",
		Expert:  "system_architect",
		UseRAG:  false,
	})
	if err != nil {
		return err
	}
	count := 0
	for ev := range events {
		if ev.Type == "token" {
			var data map[string]string
			json.Unmarshal(ev.Data, &data)
			fmt.Print(data["delta"])
			count++
			if count >= 20 {
				break
			}
		}
	}
	fmt.Println()

	if resp.ElapsedSeconds == 0 {
		return errors.New("no elapsed_s")
	}
	return nil
}

// ---
// | KB @CerberRus00 - Nexus Invest Team
