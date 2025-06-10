/**
 * Text to Speech JavaScript
 */
(function($) {
    'use strict';

    // Main TTS functionality
    var WpTextToSpeech = {
        player: null,
        utterance: null,
        voices: [],
        currentVoice: null,
        isPaused: false,
        currentPosition: 0,
        totalDuration: 0,

        init: function() {
            // Only initialize if speech synthesis is available
            if (typeof SpeechSynthesis === 'undefined' && typeof speechSynthesis === 'undefined') {
                $('.wp-tts-status').text('Speech synthesis not supported in this browser.');
                return;
            }

            this.setupEventListeners();
            this.loadVoices();
        },

        // Set up all button click events
        setupEventListeners: function() {
            // Play/Pause button
            $('.wp-tts-play-button').on('click', function() {
                if (WpTextToSpeech.isPaused) {
                    WpTextToSpeech.resumeSpeech();
                } else {
                    WpTextToSpeech.playSpeech();
                }
            });

            // Stop button
            $('.wp-tts-stop-button').on('click', function() {
                WpTextToSpeech.stopSpeech();
            });

            // Voice selection
            $('.wp-tts-voice-select').on('change', function() {
                WpTextToSpeech.setVoice($(this).val());
            });
        },

        // Load available voices
        loadVoices: function() {
            var voiceSelect = $('.wp-tts-voice-select');

            // Clear existing options except default
            voiceSelect.find('option:not([value="default"])').remove();

            // Function to populate voices
            var populateVoiceList = function() {
                WpTextToSpeech.voices = speechSynthesis.getVoices();

                if (WpTextToSpeech.voices.length > 0) {
                    // Add voices to select dropdown
                    $.each(WpTextToSpeech.voices, function(index, voice) {
                        voiceSelect.append(
                            $('<option>', {
                                value: index,
                                text: voice.name + ' (' + voice.lang + ')'
                            })
                        );
                    });

                    // Set default voice
                    WpTextToSpeech.setVoice(0);
                }
            };

            // Chrome loads voices asynchronously
            if (typeof speechSynthesis !== 'undefined' && speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = populateVoiceList;
            }

            // For other browsers, try immediate loading
            populateVoiceList();
        },

        // Set the voice to use
        setVoice: function(voiceIndex) {
            if (this.voices && this.voices.length > 0 && voiceIndex >= 0) {
                this.currentVoice = this.voices[voiceIndex];
            }
        },

        // Play text
        playSpeech: function() {
            // Get text to speak from localized data (define this FIRST)
            var textToSpeak = wpTtsData.post_content || 'No text available to speak.';

            // First stop any current speech
            this.stopSpeech();

            // Create utterance
            this.utterance = new SpeechSynthesisUtterance(textToSpeak);

            // AFTER creating the utterance, do the estimation
            // Rough estimation: ~5 characters per word, ~150 words per minute
            var wordCount = textToSpeak.trim().split(/\s+/).length;
            var minutesEstimate = wordCount / 150;
            this.totalDuration = minutesEstimate * 60; // in seconds

            // Update the duration display
            var minutes = Math.floor(this.totalDuration / 60);
            var seconds = Math.floor(this.totalDuration % 60);
            $('.wp-tts-duration').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);

            // Set voice if available
            if (this.currentVoice) {
                this.utterance.voice = this.currentVoice;
            }

            // Event handlers - leave these as they are
            this.utterance.onstart = function() {
                $('.wp-tts-play-icon').hide();
                $('.wp-tts-pause-icon').show();
                $('.wp-tts-status').text('Speaking...');
            };

            this.utterance.onpause = function() {
                WpTextToSpeech.isPaused = true;
                $('.wp-tts-play-icon').show();
                $('.wp-tts-pause-icon').hide();
                $('.wp-tts-status').text('Paused');
            };

            this.utterance.onend = function() {
                WpTextToSpeech.stopSpeech();
            };

            // Add a timer to track progress
            this.progressTimer = setInterval(function() {
                if (speechSynthesis.speaking) {
                    WpTextToSpeech.currentPosition += 0.1; // Update every 100ms

                    // Update progress bar
                    var percentComplete = (WpTextToSpeech.currentPosition / WpTextToSpeech.totalDuration) * 100;
                    percentComplete = Math.min(percentComplete, 100); // Cap at 100%
                    $('.wp-tts-progress-bar').css('width', percentComplete + '%');

                    // Update current time display
                    var currentMinutes = Math.floor(WpTextToSpeech.currentPosition / 60);
                    var currentSeconds = Math.floor(WpTextToSpeech.currentPosition % 60);
                    $('.wp-tts-current-time').text(currentMinutes + ':' + (currentSeconds < 10 ? '0' : '') + currentSeconds);
                }
            }, 100);

            // Start speaking
            speechSynthesis.speak(this.utterance);
        },

        // Resume paused speech
        resumeSpeech: function() {
            if (this.isPaused && speechSynthesis.pause) {
                $('.wp-tts-play-icon').hide();
                $('.wp-tts-pause-icon').show();
                $('.wp-tts-status').text('Speaking...');
                speechSynthesis.resume();
                this.isPaused = false;
            }
        },

        // Stop speech
        stopSpeech: function() {
            if (speechSynthesis) {
                speechSynthesis.cancel();
            }

            // Reset UI
            $('.wp-tts-play-icon').show();
            $('.wp-tts-pause-icon').hide();
            $('.wp-tts-status').text('Ready');

            // Reset progress tracking
            this.isPaused = false;
            this.currentPosition = 0;
            $('.wp-tts-progress-bar').css('width', '0%');
            $('.wp-tts-current-time').text('0:00');

            // Clear progress timer if it exists
            if (this.progressTimer) {
                clearInterval(this.progressTimer);
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WpTextToSpeech.init();
    });

})(jQuery);