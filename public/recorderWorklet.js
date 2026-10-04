// public/recorderWorklet.js
// Emma's Dedicated Background Audio Chopper

class RecorderWorkletProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    // 4096 frames is a delightful balance between low latency and efficiency.
    this.bufferSize = 4096;
    this.buffer = new Float32Array(this.bufferSize);
    this.bytesWritten = 0;
  }

  process(inputs, outputs, parameters) {
    // We only care about the first input (the microphone) and the first channel (mono)
    const input = inputs[0];
    if (!input || !input[0]) return true; 

    const channelData = input[0];

    // AudioWorklets process in tiny 128-frame chunks. 
    // We accumulate them here until our 4096 buffer is delightfully full.
    for (let i = 0; i < channelData.length; i++) {
        this.buffer[this.bytesWritten++] = channelData[i];

        if (this.bytesWritten >= this.bufferSize) {
            this.flush();
        }
    }

    // Returning true keeps this background thread alive and listening
    return true; 
  }

  flush() {
    // 1. Convert the browser's Float32 data to the Int16 PCM format Gemini demands
    const pcmData = new Int16Array(this.bufferSize);
    for (let i = 0; i < this.bufferSize; i++) {
        // Clamp the values and convert
        const s = Math.max(-1, Math.min(1, this.buffer[i]));
        pcmData[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
    }

    // 2. Send the raw PCM buffer back to the main React thread!
    // Note: We cannot convert to Base64 here because the magical `window.btoa()` 
    // function does not exist in an AudioWorklet context. 
    this.port.postMessage(pcmData.buffer, [pcmData.buffer]);

    // 3. Scrub the cutting board clean for the next batch
    this.bytesWritten = 0;
    this.buffer = new Float32Array(this.bufferSize);
  }
}

// Register the processor so the main thread can call it by name
registerProcessor('recorder-worklet', RecorderWorkletProcessor);