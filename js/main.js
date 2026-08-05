"use strict";

var themeStorageKey = "foxnetwork-theme";

function getSavedTheme() {
    try {
        return localStorage.getItem(themeStorageKey);
    } catch (e) {
        return null;
    }
}

function saveTheme(theme) {
    try {
        localStorage.setItem(themeStorageKey, theme);
    } catch (e) {
        return;
    }
}

function getPreferredTheme() {
    var saved = getSavedTheme();
    if (saved === "dark" || saved === "light") {
        return saved;
    }
    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
        return "dark";
    }
    return "light";
}

function applyTheme(theme) {
    var isDark = theme === "dark";
    $("body").toggleClass("dark-mode", isDark);
    $(".theme-toggle").each(function() {
        $(this)
            .text(isDark ? "Light mode" : "Dark mode")
            .attr("aria-label", isDark ? "Switch to light mode" : "Switch to dark mode")
            .attr("aria-pressed", isDark ? "true" : "false")
            .attr("title", isDark ? "Switch to light mode" : "Switch to dark mode");
    });
}

$(function() {
    applyTheme(getPreferredTheme());

    $(".theme-toggle").on("click", function() {
        var current = $("body").hasClass("dark-mode") ? "dark" : "light";
        var next = current === "dark" ? "light" : "dark";
        applyTheme(next);
        saveTheme(next);
    });
});

// Add Slider functionality to the #testimonials section in the home page.
var testimonialsSlider = $("#testimonials #testimonials-slider");
testimonialsSlider.slick({
    dots: false,
    arrows: true,
    infinite: false,
    slidesToShow: 1,
    slidesToScroll: 1
});
// Add Slider functionality to the testimonials in the "Sign in" and "Sign out" pages.
var miniTestimonialsSlider = $("#form-section .mini-testimonials-slider");
miniTestimonialsSlider.slick({
    dots: true,
    arrows: false,
    infinite: false,
    autoplay: true,
    speed: 200
});
// Add Slider functionality to the info-slider in the about page.
var infoSlider = $("#page-head .info-slider");
infoSlider.slick({
    dots: true,
    arrows: false,
    infinite: false,
    autoplay: true,
    speed: 200
});
$(window).on("load", function() {
    // Adding animation to the #main-slider
    $('#main-slider .slick-active > div:nth-child(1)').addClass("animated");
    $('#main-slider .slick-active > div:nth-child(2)').addClass("animated animation-delay1");
    // Counter slider functions in "CUSTOM HOSTING PLAN" section on the homepage
    var cPlan = $('#c-plan');
    cPlan.slider({
        tooltip: 'always'
    });
    var valueHolder = $('.slider .tooltip.tooltip-main','#custom-plan');
    valueHolder.css("margin-left", (valueHolder.outerWidth()/2)*-1+"px");
    cPlan.on("slide", function(e) {
        $('.slider .tooltip-up','#custom-plan').text(e.value/20);
        $('.price','#custom-plan').text($(this).data("currency") + e.value/20);
        $('.feature1 span','#custom-plan').text(e.value);
        $('.feature2 span','#custom-plan').text(e.value*98);
        valueHolder.css("margin-left", (valueHolder.outerWidth()/2)*-1+"px");
    });
    cPlan.value = cPlan.data("slider-value");
    $('.slider .tooltip','#custom-plan').append('<div class="tooltip-up"></div>');
    $('.slider .tooltip-up','#custom-plan').text(cPlan.value/20);
    $('.slider .tooltip-inner','#custom-plan').attr("data-unit",cPlan.data("unit"));
    $('.slider .tooltip-up','#custom-plan').attr("data-currency",cPlan.data("currency"));
    
    $('.price','#custom-plan').text(cPlan.data("currency") + cPlan.value/20);
    $('.feature1 span','#custom-plan').text(cPlan.value);
    $('.feature2 span','#custom-plan').text(cPlan.value*98);

    // Features Section click function
    var featureIconHolder = $("#features-links-holder .feature-icon-holder");
    
    featureIconHolder.on("click",function(){
        featureIconHolder.removeClass("opened");
        $(this).addClass("opened");
        $("#features-holder .show-details").removeClass("show-details");
        $("#features-holder .feature-d"+$(this).data("id")).addClass("show-details");
    });
    
    // Fix #features-holder height in features section
    var featuresHolder = $("#features-holder");
    var featuresLinksHolder = $("#features-links-holder");
    var featureBox = $("#features-holder .show-details");
    
    featuresHolder.css("height",featureBox.height()+120);
    featuresLinksHolder.css("height",featureBox.height()+120);

    // Fix #features-holder height in features section
    $(window).on("resize",function() {
        featuresHolder.css("height",featureBox.height()+120);
        featuresLinksHolder.css("height",featureBox.height()+120);
        return false;
    });
    
    // Apps Section hover function
    var appHolder = $("#apps .app-icon-holder");
    
    appHolder.on("mouseover",function(){
        appHolder.removeClass("opened");
        $(this).addClass("opened");
        $("#apps .show-details").removeClass("show-details");
        $("#apps .app-details"+$(this).data("id")).addClass("show-details");
    });
    
    // More Info Section hover function
    var infoLink = $("#more-info .info-link");
    
    infoLink.on("mouseover",function(){
        infoLink.removeClass("opened");
        $(this).addClass("opened");
        $("#more-info .show-details").removeClass("show-details");
        $("#more-info .info-d"+$(this).data("id")).addClass("show-details");
    });
    
    // Servers Marker Location in our servers page
    var locationsList = [["California",97,48,"r"],["Costa Rika",212,31,"l"],["Vancouver",136,161,"r"],["Brazil",303,233,"r"],["Alexandria",149,349,"l"],["Dubai",174,469,"l"],["Delhi",204,605,"r"],["Munech",91,417,"r"],["Barcelona",112,279,"l"],["Moscow",41,554,"r"],["Hong Kong",151,663,"r"],["Melborne",356,688,"l"],["Pulau Ujong",265,578,"l"]];
    
    var serversLocationHolder = $('#serversmap.st .servers-location-holder');
    for(var i=0;i<=locationsList.length-1;i++){
        var sMarkerDir = locationsList[i][3];
        var leftText = "";
        var rightText = "";
        if(sMarkerDir=="r"){
            leftText = "";
            rightText = locationsList[i][0];
        }else if(sMarkerDir=="l"){
            leftText = locationsList[i][0];
            rightText = "";
        }
        serversLocationHolder.append('<div class="server-marker" style="top:'+locationsList[i][1]+'px;left:'+locationsList[i][2]+'px;"><span class="left-text">'+leftText+'</span><span class="marker-icon"></span><span class="right-text">'+rightText+'</span></div>');
    }

    // Add subtle sticky-nav state once users scroll past the hero area.
    var navBar = $("#nav");
    $(window).on("scroll", function() {
        navBar.toggleClass("is-scrolled", $(this).scrollTop() > 30);
    });

    // Improve in-page anchor navigation with offset for the fixed header.
    $('a[href^="#"]').on("click", function(e) {
        var targetId = $(this).attr("href");
        if (!targetId || targetId === "#") {
            return;
        }
        var target = $(targetId);
        if (!target.length) {
            return;
        }
        e.preventDefault();
        $("html, body").animate({
            scrollTop: target.offset().top - 80
        }, 500);
    });

    // Animate trust metrics when they enter the viewport.
    var statNumbers = $("#info .stat-number");
    var statsAnimated = false;
    function animateStats() {
        if (statsAnimated || !statNumbers.length) {
            return;
        }
        var trigger = $("#info").offset().top - $(window).height() + 100;
        if ($(window).scrollTop() < trigger) {
            return;
        }
        statsAnimated = true;
        statNumbers.each(function() {
            var node = $(this);
            var originalText = node.text().trim();
            var numericPart = parseFloat(originalText.replace(/[^0-9.]/g, ""));
            if (isNaN(numericPart)) {
                return;
            }
            var suffix = originalText.replace(/[0-9.]/g, "");
            $({ count: 0 }).animate({ count: numericPart }, {
                duration: 1200,
                easing: "swing",
                step: function(now) {
                    var rounded = originalText.indexOf(".") > -1 ? now.toFixed(1) : Math.floor(now);
                    node.text(rounded + suffix);
                },
                complete: function() {
                    node.text(originalText);
                }
            });
        });
    }
    animateStats();
    $(window).on("scroll", animateStats);

    // FAQ controls with keyboard support and one-open-at-a-time behavior.
    var faqItems = $("#faq .faq-item");
    $("#faq .faq-question").on("click keydown", function(e) {
        if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") {
            return;
        }
        e.preventDefault();
        var item = $(this).closest(".faq-item");
        var willOpen = !item.hasClass("active");
        faqItems.removeClass("active").find(".faq-question").attr("aria-expanded", "false");
        if (willOpen) {
            item.addClass("active");
            item.find(".faq-question").attr("aria-expanded", "true");
        }
    });

    // Provide lightweight feedback for contact form submissions.
    var contactForm = $("#contactform");
    if (contactForm.length) {
        contactForm.on("submit", function(e) {
            e.preventDefault();
            var status = $("#contact-form-status");
            if (!this.checkValidity()) {
                status.removeClass("success").addClass("error").text("Please complete all required fields before sending.");
                return;
            }
            status.removeClass("error").removeClass("success").text("Sending...");
            var form = this;
            $.ajax({
                url: "/api/contact-submit.php",
                method: "POST",
                data: $(form).serialize(),
                dataType: "json"
            }).done(function(res) {
                if (res && res.ok) {
                    status.removeClass("error").addClass("success").text(res.message || "Message sent.");
                    form.reset();
                    return;
                }
                status.removeClass("success").addClass("error").text((res && res.message) ? res.message : "Could not submit your message.");
            }).fail(function() {
                status.removeClass("success").addClass("error").text("Could not submit right now. Please open a support ticket.");
            });
        });
    }

});