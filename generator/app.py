from flask import Flask, request, jsonify, Response, stream_with_context, render_template
from data import FORMS, TOPICS, TARGETS
from ai import generate_complaint, generate_complaint_stream
from werkzeug.middleware.proxy_fix import ProxyFix
import json

app = Flask(__name__)
app.secret_key = '6f59b7fcb946d6f4247d578cc5180dd1'
app.wsgi_app = ProxyFix(app.wsgi_app, x_proto=1, x_host=1)

@app.route('/topics', methods=['GET'])
def get_topics():
    """Get all available topics."""
    processed = TOPICS.copy()
    for topic in processed.values():
        topic.pop("desc", None)
        topic.pop("law", None)
    return jsonify(processed)

@app.route('/form_types', methods=['GET'])
def get_form_types():
    """Get all available form types."""
    processed = FORMS.copy()
    for form_type, form_desc in processed.items():
        processed[form_type] = {
            "desc": form_desc,
            "targets": [k for k, v in TARGETS.items() if form_type in v["forms"]]
        }
    return jsonify(processed)

@app.route('/targets', methods=['GET'])
def get_targets():
    return jsonify(TARGETS)

@app.route('/generate', methods=['POST'])
def generate():
    """Generate a complaint based on the provided parameters."""
    data = request.get_json()
    
    # Validate required fields
    required_fields = ['topic_ids', 'form_type', 'target']
    if not all(field in data for field in required_fields):
        return jsonify({"error": f"Missing required fields. Required: {', '.join(required_fields)}"}), 400
    
    topic_ids = data['topic_ids']
    form_type = data['form_type']
    target = data['target']
    
    # Validate input values
    if not isinstance(topic_ids, list) or not all(tid in TOPICS for tid in topic_ids):
        return jsonify({"error": "Invalid topic_ids provided"}), 400
    
    if form_type not in FORMS:
        return jsonify({"error": "Invalid form_type provided"}), 400
    
    if target not in TARGETS or form_type not in TARGETS[target]["forms"]:
        return jsonify({"error": "Invalid target for the selected form_type"}), 400
    
    try:
        # Generate the complaint
        complaint, price = generate_complaint(topic_ids, form_type, target)
        
        # Prepare response
        response = {
            "status": "success",
            "data": {
                "complaint": complaint,
                "topics": [{"id": tid, "title": TOPICS[tid]["title"]} for tid in topic_ids],
                "form_type": form_type,
                "form_description": FORMS[form_type],
                "target": target,
                "target_title": TARGETS[target]["title"],
                "price": price
            }
        }
        
        return jsonify(response)
        
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/stream-demo')
def stream_demo():
    """Serve the streaming demo page."""
    return render_template('stream_demo.html')

@app.route('/stream', methods=['POST'])
def stream():
    """Stream the complaint generation process."""
    data = request.get_json()
    
    # Get parameters from request
    topics = data.get('topics', [])
    form_type = data.get('form_type', '')
    target = data.get('target', '')
    name = data.get('name', '')
    city = data.get('city', '')
    
    if not all([topics, form_type, target]):
        return jsonify({"error": "Missing required parameters"}), 400
    
    # Get the generator and estimated price
    generator, estimated_price = generate_complaint_stream(topics, form_type, target, name, city)
    
    # Create a streaming response
    def generate():
        try:
            for chunk in generator:
                # Format as SSE (Server-Sent Events)
                yield f"data: {json.dumps({'chunk': chunk, 'done': False})}\n\n"
            # Send final done message
            yield f"data: {json.dumps({'done': True, 'estimated_price': estimated_price})}\n\n"
        except Exception as e:
            yield f"data: {json.dumps({'error': str(e)})}\n\n"
    
    return Response(
        stream_with_context(generate()),
        mimetype='text/event-stream',
        headers={
            'Cache-Control': 'no-cache',
            'Connection': 'keep-alive',
            'X-Accel-Buffering': 'no'  # Disable buffering for nginx
        }
    )

if __name__ == "__main__":
    print("Starting API server on http://localhost:5001")
    app.run(debug=True, port=5001, threaded=True)
