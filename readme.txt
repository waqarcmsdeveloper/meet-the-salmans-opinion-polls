=== Meet the Salmans – Complete Opinion Polls ===
Contributors: syed-abdul-quaseem
Tags: polls, opinion polls, ajax, elementor, registration, charts
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later

A registration-first opinion polling system with dynamic categories, AJAX search, randomized answer choices, optional gender/context, and immediate horizontal result charts.

== Installation ==

1. Upload the plugin ZIP from WordPress Dashboard > Plugins > Add New Plugin > Upload Plugin.
2. Activate it.
3. The plugin automatically creates these pages:
   - Poll Registration: [mts_poll_register]
   - Poll Login: [mts_poll_login]
   - Opinion Polls: [mts_opinion_polls]
   - My Poll Answers: [mts_my_poll_answers]
4. Open Opinion Polls > Settings to confirm the page assignments and styling.
5. Edit the generated pages in Elementor when needed, keeping the shortcode in an Elementor Shortcode widget.

== Required User Flow ==

Poll Registration -> Poll Login -> Opinion Polls -> Category/Search -> Answer -> Live Results

Visitors opening the assigned Opinion Polls page while logged out are sent to registration. After registration they are sent to login. A successful login sends them to the Opinion Polls page.

== Client Requirements Covered ==

* Account registration and login
* One registered user response per poll
* Church
* Dating
* Divorced or Widowed
* Emotional Well-being
* Friends & Family
* God
* Life & Work
* The Opposite Sex
* Parenting & Childlessness
* Past Relationships
* Sexuality
* Search by Keyword
* Most Popular
* Most Recent
* Answer button and View Results button
* Gender: Male, Female, or Skip
* Random answer order to reduce first-listed bias
* Other option that opens a text field
* Add Context button and optional context field
* Immediate results without a minimum-vote requirement
* Total vote number
* Horizontal bar graph with percentage and vote count
* Sample poll questions across all categories
* AJAX filters and popups without page reloads
* CSV response export for administrators

== Shortcodes ==

[mts_poll_register]
[mts_poll_login]
[mts_opinion_polls]
[mts_my_poll_answers]
[mts_single_opinion_poll id="123"]

Elementor pages can use:
[mts_opinion_polls show_intro="no" open_first="yes"]

== Managing Polls ==

Go to WordPress Dashboard > Opinion Polls > Add New Poll.

* Use the title as the poll question.
* Enter one answer choice per line.
* Assign one or more Poll Categories.
* Enable or disable randomized answer order.
* Enable or disable Other.
* Enable or disable Add Context.
* Open or close the poll.
* Decide whether a user may change an answer.

== Privacy and Data ==

Public results show combined totals only. Names, email addresses, Other text, and added context are not shown in the public chart. Administrators can export response details as CSV. One vote is enforced using the registered WordPress user ID.

== Changelog ==

= 3.0.0 =
* Completed registration-first flow.
* Added all 11 client categories and sample questions.
* Added AJAX keyword/category/popularity/recency filters.
* Added randomized answers, Other input, and Add Context.
* Added immediate total-vote horizontal result charts.
* Added responsive Elementor-friendly styling.
