(function () {
    const scriptUrl = document.currentScript && document.currentScript.src
        ? document.currentScript.src
        : new URL('assets/js/face-verification.js', document.baseURI).href;
    const MODEL_URL = new URL('../../models', scriptUrl).pathname.replace(/\/$/, '');
    const MATCH_DISTANCE_THRESHOLD = 0.62;
    let modelPromise = null;

    function ensureFaceApi() {
        if (!window.faceapi) {
            throw new Error('Face verification library is still loading. Please try again.');
        }
    }

    function loadModels() {
        ensureFaceApi();
        if (!modelPromise) {
            modelPromise = Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
            ]);
        }
        return modelPromise;
    }

    function toImage(source) {
        if (!source) {
            return Promise.reject(new Error('Face image is missing.'));
        }
        if (source instanceof HTMLImageElement || source instanceof HTMLVideoElement || source instanceof HTMLCanvasElement) {
            if (source instanceof HTMLImageElement && !source.complete) {
                return new Promise((resolve, reject) => {
                    source.onload = () => resolve(source);
                    source.onerror = () => reject(new Error('Face image could not be loaded.'));
                });
            }
            return Promise.resolve(source);
        }
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('Face image could not be loaded.'));
            image.src = source;
        });
    }

    async function descriptorFor(source, label) {
        const image = await toImage(source);
        const options = new faceapi.TinyFaceDetectorOptions({
            inputSize: 416,
            scoreThreshold: 0.25
        });
        const detections = await faceapi
            .detectAllFaces(image, options)
            .withFaceLandmarks()
            .withFaceDescriptor();
        const result = detections
            .filter((detection) => detection && detection.descriptor)
            .sort((a, b) => {
                const aBox = a.detection && a.detection.box ? a.detection.box : a.box;
                const bBox = b.detection && b.detection.box ? b.detection.box : b.box;
                const aArea = aBox ? aBox.width * aBox.height : 0;
                const bArea = bBox ? bBox.width * bBox.height : 0;
                return bArea - aArea;
            })[0];

        if (!result) {
            throw new Error('No clear face was detected in the ' + label + ' image.');
        }

        return result.descriptor;
    }

    async function verifyLiveAgainstId(liveSource, idSource) {
        await loadModels();
        const liveDescriptor = await descriptorFor(liveSource, 'live');
        const idDescriptor = await descriptorFor(idSource, 'front ID');
        const distance = faceapi.euclideanDistance(liveDescriptor, idDescriptor);
        const similarity = Math.max(0, Math.min(1, 1 - distance));
        const score = Math.round(similarity * 100);

        return {
            distance,
            score,
            matchScore: score,
            isMatch: distance <= MATCH_DISTANCE_THRESHOLD,
            threshold: MATCH_DISTANCE_THRESHOLD,
            modelUrl: MODEL_URL
        };
    }

    window.FaceVerification = {
        MODEL_URL,
        loadModels,
        verifyLiveAgainstId
    };
})();
