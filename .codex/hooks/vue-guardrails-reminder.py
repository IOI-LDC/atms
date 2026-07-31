#!/usr/bin/env python3
"""PreToolUse hook: when a Vue frontend file is about to be edited/created,
inject a reminder to apply the vue-frontend-guardrails skill.

Fires for Edit / Write / MultiEdit on feature frontend files:
  *.vue, composables/stores/*.ts under a frontend src tree, and *.css stylesheets.
Backend (.php) and non-frontend paths are ignored.
"""
import json
import sys

def main():
    try:
        payload = json.load(sys.stdin)
    except Exception:
        sys.exit(0)

    tool_input = payload.get("tool_input", {}) or {}
    path = (tool_input.get("file_path") or "").lower()
    if not path:
        sys.exit(0)

    is_vue = path.endswith(".vue")
    is_front_ts = path.endswith(".ts") and "/frontend/" in path and (
        "/composables/" in path or "/stores/" in path or "/views/" in path
        or "/components/" in path
    )
    is_style = path.endswith(".css") and "/frontend/" in path

    if not (is_vue or is_front_ts or is_style):
        sys.exit(0)

    reminder = (
        "Vue frontend file detected. Apply the **vue-frontend-guardrails** skill "
        "before and while editing. Enforce the contract: logic → composables; "
        "styling → semantic CSS classes only (no Tailwind/inline in feature files); "
        "interactions → shadcn-vue primitives (no raw button/input/select/textarea/dialog); "
        "values → design tokens (var(--…)), no hardcoded hex/oklch/px. "
        "Project UI-rules / design-system docs override the skill where they conflict."
    )

    print(json.dumps({
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "additionalContext": reminder,
        }
    }))
    sys.exit(0)

if __name__ == "__main__":
    main()
